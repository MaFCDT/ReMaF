<?php

namespace App\Service;

use App\Entity\Character;
use App\Entity\Setting;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;


class AppState {

	protected $em;
	protected $tokenStorage;
	protected $authorizationCheckerInterface;
	protected $requestStack;

	private $languages = array(
		'en' => 'english',
		'de' => 'deutsch',
		'es' => 'español',
		'fr' => 'français',
		'it' => 'italiano'
		);

	public function __construct(EntityManagerInterface $em, TokenStorageInterface $tokenStorage, AuthorizationCheckerInterface $authorizationChecker, RequestStack $requestStack) {
		$this->em = $em;
		$this->tokenStorage = $tokenStorage;
		$this->authorizationChecker = $authorizationChecker;
		$this->requestStack = $requestStack;
	}

	public function availableTranslations() {
		return $this->languages;
	}

	public function getCharacter($required=true, $ok_if_dead=false, $ok_if_notstarted=false) {
		/* This used to throw exceptions rather than adding flashes and returning strings.
		The change was done in order to ensure that when you're somewhere you shouldn't be,
		that the game is smart enough to redirect you to the right spot.

		Technically speaking, the first two returns don't actually do anything, because they're
		intercepted by the Symfony Firewall and sent to the secuirty/detect route which does
		something similar. */
		# Check if we have a user first
		$token = $this->tokenStorage->getToken();
		if (!$token) {
			if (!$required) {
				return null;
			} else {
				return 'fos_user_security_login';
			}
		}
		$user = $token->getUser();
		if (!$user || ! $user instanceof UserInterface) {
			if (!$required) {
				return null;
			} else {
				return 'fos_user_security_login';
			}
		}

		# Let the ban checks begin...
		if ($this->authorizationChecker->isGranted('ROLE_BANNED_MULTI')) {
			if (!$required) { return null; } else { throw new AccessDeniedException('error.banned.multi'); }
		}

		# Check if we have a character, if not redirect to character list.
		$character = $user->getCurrentCharacter();
		$session = $this->requestStack->getSession();
		if (!$character) {
			if (!$required) {
				return null;
			} else {
				$session->getFlashBag()->add('error', 'error.missing.character');
				return 'bm2_characters';
			}
		}
		# Check if it's okay that the character is dead. If not, then character list they go.
		if (!$ok_if_dead && !$character->isAlive()) {
			if (!$required) {
				return null;
			} else {
				$session->getFlashBag()->add('error', 'error.missing.soul');
				return 'bm2_characters';
			}
		}
		# Check if it's okay that the character is not started. If not, then character list they go.
		if (!$ok_if_notstarted && !$character->getLocation()) {
			if (!$required) {
				return null;
			} else {
				$session->getFlashBag()->add('error', 'error.missing.location');
				return 'bm2_characters';
			}
		}

		if ($character->isAlive()) {
			$character->setLastAccess(new \DateTime('now')); // no flush here, most actions will issue one anyways and we don't need 100% reliability
		}
		return $character;
	}

	public function getDate($cycle=null) {
		// our in-game date - 6 days a week, 60 weeks a year = 1 year about 2 months
		if (null===$cycle) {
			$cycle = $this->getCycle();
		}

		$year = floor($cycle/360)+1;
		$week = floor($cycle%360/6)+1;
		$day = ($cycle%6)+1;
		return array('year'=>$year, 'week'=>$week, 'day'=>$day);
	}

	public function getCycle() {
		return (int)($this->getGlobal('cycle', 0));
	}

	public function getGlobal($name, $default=false) {
		$setting = $this->em->getRepository(Setting::class)->findOneByName($name);
		if (!$setting) return $default;
		return $setting->getValue();
	}
	public function setGlobal($name, $value) {
		$setting = $this->em->getRepository(Setting::class)->findOneByName($name);
		if (!$setting) {
			$setting = new Setting();
			$setting->setName($name);
			$this->em->persist($setting);
		}
		$setting->setValue($value);
		$this->em->flush($setting);
	}


	public function setSessionData(Character $character) {
		$session = $this->requestStack->getSession();
		$session->clear();
		if ($character->isAlive()) {
			if ($character->getInsideSettlement()) {
				$session->set('nearest_settlement', $character->getInsideSettlement());
			} elseif ($character->getLocation()) {
				$near = $this->findNearestSettlement($character);
				$session->set('nearest_settlement', $near[0]);
			}
			#$this->session->set('soldiers', $character->getLivingSoldiers()->count());
			#$this->session->set('entourage', $character->getLivingEntourage()->count());
			$query = $this->em->createQuery('SELECT s.id, s.name FROM App:Settlement s WHERE s.owner = :me');
			$query->setParameter('me', $character);
			$settlements = array();
			foreach ($query->getResult() as $row) {
				$settlements[$row['id']] = $row['name'];
			}
			$session->set('settlements', $settlements);
			$realms = array();
			foreach ($character->findRulerships() as $realm) {
				$realms[$realm->getId()] = $realm->getName();
			}
			$session->set('realms', $realms);
		}
	}

	public function findEmailOptOutToken(User $user) {
		return $user->getEmailOptOutToken()?$user->getEmailOptOutToken():$this->generateEmailOptOutToken($user);
	}

	public function generateEmailOptOutToken(User $user) {
		$token = $this->generateToken();
		$user->setEmailOptOutToken($token);
		$this->em->flush();
		return $token;
	}

	public function generateToken($length = 128, $method = 'trimbase64') {
		if ($method = 'trimbase64') {
			$token = rtrim(strtr(base64_encode(random_bytes($length)), '+/', '-_'), '=');
		}
		return $token;
	}

	// FIXME: this is duplicate code from Geography.php but I can't inject the geography service because it would create a circular injection (as it depends on appstate)
	private function findNearestSettlement(Character $character) {
		$query = $this->em->createQuery('SELECT s, ST_Distance(g.center, c.location) AS distance FROM App:Settlement s JOIN s.geo_data g, BM2SiteBundle:Character c WHERE c = :char ORDER BY distance ASC');
		$query->setParameter('char', $character);
		$query->setMaxResults(1);
		return $query->getSingleResult();
	}


}