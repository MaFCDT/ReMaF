<?php

namespace App\Controller;

use App\Entity\Patron;
use App\Form\CultureType;
use App\Form\GiftType;
use App\Form\SubscriptionType;
use Doctrine\ORM\EntityManagerInterface;
use Patreon\OAuth as POA;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class PaymentController extends AbstractController {

	private array $giftchoices = array(100, 200, 300, 400, 500, 600, 800, 1000, 1200, 1500, 2000, 2500);
	private EntityManagerInterface $em;
	private Security $sec;

	public function __construct(EntityManagerInterface $em, Security $sec) {
		$this->em = $em;
		$this->sec = $sec;
	}

	private function fetchPatreon($creator = null) {
		if (!$creator) {
			$query = $this->em->createQuery("SELECT p FROM App:Patreon p WHERE p.id > 0");
			$result = $query->getResult();
		} else {
			$query = $this->em->createQuery("SELECT p FROM App:Patreon p WHERE p.creator = :name");
			$query->setParameters(["name"=>$creator]);
			$result = $query->getSingleResult();
		}
		return $result;
	}

	#[Route ('/payment', name:'maf_payment')]
	public function paymentAction(Request $request): Response {
		$banned = $this->app->checkbans();
		if ($banned instanceof AccessDeniedException) {
			throw $banned;
		}
		$user = $this->getUser();

		$form = $this->createFormBuilder()
			->add('hash', 'text', array(
				'required' => true,
				'label' => 'account.code.label',
			))
			->add('submit', 'submit', array('label'=>'account.code.submit'))
			->getForm();

		$redeemed = false;

		$form->handleRequest($request);
		if ($form->isValid()) {
			$data = $form->getData();

			list($code, $result) = $this->get('payment_manager')->redeemHash($user, $data['hash']);

			if ($result === true) {
				$redeemed = $code;
			} else {
				$form->addError(new FormError($this->get('translator')->trans($result)));
			}
		}

		return $this->render('Payment/payment.html.twig', [
			'form' => $form->createView(),
			'redeemed' => $redeemed
		]);
	}

	#[Route ('/payment/stripe/{currency}/{amount}', name:'maf_stripe', requirements:['currency'=>'[A-Z]+', 'amount'=>'\d+'])]
	public function stripeAction($currency, $amount, Request $request): RedirectResponse {
		$banned = $this->app->checkbans();
		if ($banned instanceof AccessDeniedException) {
			throw $banned;
		}
		$user = $this->getUser();

		$success = $this->generateUrl('maf_stripe_success', [], true);
		$cancel = $this->generateUrl('bm2_payment', [], true);
		$checkout = $this->get('payment_manager')->buildStripeIntent($currency, $amount, $user, $success, $cancel);
		if ($checkout === 'notfound') {
			$this->addFlash('error', "Unable to locate the requested product.");
			return $this->redirectToRoute('bm2_payment');
		} else {
			return $this->redirect($checkout->url);
		}
	}

	#[Route ('/payment/stripe_success}', name:'maf_stripe_success')]
	public function stripeSuccessAction(Request $request): RedirectResponse {
		$user = $this->getUser();
		$user_id = $user->getId();
		try {
			list($result, $items) = $this->get('payment_manager')->retrieveStripe($request->query->get('session_id'));
		} catch (Exception $e) {
			$this->addFlash('error', "Stripe Payment Failed. If you've received this it's because we weren't able to complete the transaction for some reason. Please let an administrator know about this in a direct message either on Discord or via email to andrew@iungard.com.");
			return $this->redirectToRoute('bm2_payment');
		}

		$total = $result->amount_subtotal/100;
		$currency = strtoupper($result->currency);
		$txid = $result->payment_intent;
		$status = strtolower($result->payment_status);
		$pid = $items->data[0]->price->id;

		if ($status === 'paid' || $status == 'no_payment_required') {
			$txt = "Stripe Payment calback: $total $currency // for user ID $user_id // tx id $txid";
			$this->get('payment_manager')->log_info($txt);
			$this->get('payment_manager')->account($user, 'Stripe Payment', $pid, $txid);
			$this->get('notification_manager')->spoolPayment('M&F '.$txt);
			$this->addFlash('notice', 'Payment Successful! Thank you!');
			return $this->redirectToRoute('bm2_payment');
		} else {
			$this->addFlash('error', "Stripe Payment Incomplete. If you believe you reached this incorrectly, please contact an Adminsitrator. Having your M&F username and transaction time handy will make this easier to look into. Transactions that aren't immediate will require manual processing at this time.");
			return $this->redirectToRoute('bm2_payment');
		}
	}

	#[Route ('/payment/stripe_cancel', name:'maf_stripe_cancel')]
	public function stripeCancelAction(Request $request): RedirectResponse {
		$this->addFlash('warning', "You appear to have cancelled your payment. Transaction has ended.");
		return $this->redirectToRoute('bm2_payment');
	}

	#[Route ('/payment/credits', name:'maf_payment_credits')]
	public function creditsAction(): Response {
		$banned = $this->app->checkbans();
		if ($banned instanceof AccessDeniedException) {
			throw $banned;
		}
		$user = $this->getUser();

		return $this->render('Payment/credits.html.twig', [
			'myfee' => $this->get('payment_manager')->calculateUserFee($user),
			'concepturl' => $this->generateUrl('bm2_site_default_paymentconcept')
		]);
	}

	#[Route ('/payment/subscription', name:'maf_payment_subscription')]
	public function subscriptionAction(Request $request): RedirectResponse|Response {
		$banned = $this->app->checkbans();
		if ($banned instanceof AccessDeniedException) {
			throw $banned;
		}
		$user = $this->getUser();
		$levels = $this->get('payment_manager')->getPaymentLevels();

		$sublevel = [];
		foreach ($user->getPatronizing() as $patron) {
			if ($patron->getCreator()->getCreator() == 'andrew' && $patron->getStatus() == 'active_patron') {
				$sublevel['andrew'] = $patron->getCurrentAmount();
			}
		}

		$form = $this->createForm(new SubscriptionType($levels, $user->getAccountLevel()));
		$form->handleRequest($request);
		if ($form->isValid()) {
			$data = $form->getData();

			// TODO: this should require an e-mail confirmation
			// TODO: should not allow lowering while above (new) character limit

			$check = $this->get('payment_manager')->changeSubscription($user, $data['level']);
			if ($check) {
				$this->addFlash('notice', 'account.sub.changed');
				return $this->redirectToRoute('bm2_site_payment_credits');
			}
		}

		return $this->render('Payment/subscription.html.twig', [
			'myfee' => $this->get('payment_manager')->calculateUserFee($user),
			'refund' => $this->get('payment_manager')->calculateRefund($user),
			'levels' => $levels,
			'sublevel' => $sublevel,
			'concepturl' => $this->generateUrl('bm2_site_default_paymentconcept'),
			'creators' => $this->fetchPatreon(),
			'form'=> $form->createView()
		]);
	}

	#[Route ('/payment/patreon/update', name:'maf_patreon_update')]
	public function patreonUpdateAction(Request $request): RedirectResponse {
		$banned = $this->app->checkbans();
		if ($banned instanceof AccessDeniedException) {
			throw $banned;
		}
		$user = $this->getUser();
		$em = $this->getDoctrine()->getManager();
		$patreons = $user->getPatronizing();
		$pm = $this->get('payment_manager');

		$now = new \DateTime('now');
		$amount = 0;
		$wait = false;
		if ($patreons->count() > 1) {
			$wait = true;
		}

		foreach ($patreons as $patron) {
			if ($patron->getExpires() < $now) {
				$pm->refreshPatreonTokens($patron);
				usleep(100000); #Wait a tenth a second, then continue, to avoid overloading the API.
			}
			list($status, $entitlement) = $pm->refreshPatreonPledge($patron);
			# NOTE: Expand this later for other creators if we have any.
			if ($patron->getCreator()->getCreator()=='andrew') {
				$amount += $entitlement;
			}
		}
		if ($amount > 0) {
			$amount = $amount/100;
		}
		$em->flush();
		$this->addFlash('notice', $this->get('translator')->trans('account.patronizing', ['%entitlement%'=>$amount]));
		return $this->redirectToRoute('bm2_account');
	}

	#[Route ('/payment/patreon/{creator}', name:'maf_patreon', requirements:['creator'=>'[A-Za-z]+'])]
	public function patreonAction(Request $request, $creator): RedirectResponse {
		$banned = $this->app->checkbans();
		if ($banned instanceof AccessDeniedException) {
			throw $banned;
		}
		$user = $this->getUser();
		$em = $this->getDoctrine()->getManager();

		$code = $request->query->get('code');
		$creator = $this->fetchPatreon($creator);
		if (isset($code) && !empty($code)) {
			$pm = $this->get('payment_manager');
			$auth = new POA($creator->getClientId(), $creator->getClientSecret());
			$tokens = $auth->get_tokens($code, $creator->getReturnUri());
			$patron = $em->getRepository('App:Patron')->findOneBy(["user"=>$user, "creator"=>$creator]);
			if (!$patron) {
				$patron = new Patron();
				$em->persist($patron);
				$patron->setUser($user);
				$patron->setCreator($creator);
			}
			$patron->setAccessToken($tokens['access_token']);
			$patron->setRefreshToken($tokens['refresh_token']);
			$patron->setExpires(new \DateTime('+'.$tokens['expires_in'].' seconds'));
			list($status, $entitlement) = $pm->refreshPatreonPledge($patron);
			if ($status === false) {
				#This only returns false if the API spits garbage at us.
				$this->addFlash('error', $this->get('translator')->trans('account.patreonapifailure'));
				return $this->redirectToRoute('bm2_account');
			}
			$em->flush();
			$this->addFlash('notice', $this->get('translator')->trans('account.patronizing', ['%entitlement%'=>$entitlement/100]));
			return $this->redirectToRoute('bm2_account');
		} else {
			$this->addFlash('notice', $this->get('translator')->trans('account.patronfailure'));
			return $this->redirectToRoute('bm2_site_payment_subscription');
		}
	}

	#[Route ('/payment/culture', name:'maf_payment_culture')]
	public function cultureAction(Request $request): Response {
		$banned = $this->app->checkbans();
		if ($banned instanceof AccessDeniedException) {
			throw $banned;
		}
		$em = $this->getDoctrine()->getManager();
		$allcultures = $em->createQuery('SELECT c FROM App:Culture c INDEX BY c.id')->getResult();
		$nc = $em->createQuery('SELECT c.id as id, count(n.id) as amount FROM App:NameList n JOIN n.culture c GROUP BY c.id')->getResult();
		$namescount = array();
		foreach ($nc as $ncx) {
			$namescount[$ncx['id']] = $ncx['amount'];
		}

		$form = $this->createForm(new CultureType($this->getUser(), false));
		$form->handleRequest($request);
		if ($form->isValid()) {
			$data = $form->getData();

			$buying = $data['culture'];
			$total = 0;
			foreach ($buying as $buy) {
				$total += $buy->getCost();
			}
			if ($total > $this->getUser()->getCredits()) {
				$form->addError(new FormError($this->get('translator')->trans("account.culture.notenoughcredits")));
			} else {
				foreach ($buying as $buy) {
					// TODO: error handling here?
					$this->get('payment_manager')->spend($this->getUser(), "culture pack", $buy->getCost());
					$this->getUser()->getCultures()->add($buy);
				}
				$em->flush();

				return $this->render('Payment/culture.html.twig', [
					'bought'=>$buying,
					'namescount'=>$namescount
				]);
			}
		}

		return $this->render('Payment/culture.html.twig', [
			'cultures'=>$allcultures,
			'namescount'=>$namescount,
			'form'=>$form->createView()
		]);
	}

	#[Route ('/payment/gift', name:'maf_payment_gift')]
	public function giftAction(Request $request): array|Response {
		$banned = $this->app->checkbans();
		if ($banned instanceof AccessDeniedException) {
			throw $banned;
		}
		$user = $this->getUser();

		$form = $this->createForm(new GiftType($this->giftchoices, false));
		$form->handleRequest($request);
		if ($form->isValid()) {
			$data = $form->getData();
			$value = $this->giftchoices[$data['credits']];

			$em = $this->getDoctrine()->getManager();
			$target = $em->getRepository('App:User')->findOneByEmail($data['email']);
			if (!$target) {
				sleep(1); // to make brute-forcing e-mail addresses a bit slower
				return array('error'=>'notarget');
			}
			if ($target == $user) {
				return array('error'=>'self');
			}

			$code = $this->get('payment_manager')->createCode($value, 0, $data['email'], $user);
			$this->get('payment_manager')->spend($user, "gift", $value);

			$em->flush();

			$text = $this->get('translator')->trans('account.gift.mail.body', array("%credits%"=>$value, "%code%"=>$code->getCode(), "%message%"=>strip_tags($data['message'])));
			$message = \Swift_Message::newInstance()
				->setSubject($this->get('translator')->trans('account.gift.mail.subject', array()))
				->setFrom(array('mafserver@lemuriacommunity.org' => $this->get('translator')->trans('mail.sender', array(), "communication")))
				->setTo($data['email'])
				->setCc($user->getEmail())
				->setBody($text, 'text/html')
				->setMaxLineLength(1000)
			;
			$numSent = $this->get('mailer')->send($message);
			$this->get('logger')->info("sent gift: ($numSent) from ".$user->getId()." to ".$data['email']." for $value credits");

			return $this->render('Payment/gift.html.twig', [
				'success'=>true, 'credits'=>$value
			]);

		}

		return $this->render('Payment/gift.html.twig', [
			'form'=>$form->createView()
		]);
	}

	#[Route ('/payment/invite', name:'maf_payment_invite')]
	public function inviteAction(Request $request): Response {
		$banned = $this->app->checkbans();
		if ($banned instanceof AccessDeniedException) {
			throw $banned;
		}
		$user = $this->getUser();


		$form = $this->createForm(new GiftType($this->giftchoices, true));
		$form->handleRequest($request);
		if ($form->isValid()) {
			$data = $form->getData();
			$value = $this->giftchoices[$data['credits']];

			$code = $this->get('payment_manager')->createCode($value, 0, $data['email'], $user);
			$this->get('payment_manager')->spend($user, "gift", $value);

			$this->getDoctrine()->getManager()->flush();

			$text = $this->get('translator')->trans('account.invite.mail.body', array("%credits%"=>$value, "%code%"=>$code->getCode(), "%message%"=>strip_tags($data['message'])));
			$message = \Swift_Message::newInstance()
				->setSubject($this->get('translator')->trans('account.invite.mail.subject', array()))
				->setFrom(array('mafserver@lemuriacommunity.org' => $this->get('translator')->trans('mail.sender', array(), "communication")))
				->setTo($data['email'])
				->setCc($user->getEmail())
				->setBody($text, 'text/html')
				->setMaxLineLength(1000)
			;
			$numSent = $this->get('mailer')->send($message);
			$this->get('logger')->info("sent friend invite: ($numSent) from ".$user->getId()." to ".$data['email']." for $value credits");

			return $this->render('Payment/invite.html.twig', [
				'success'=>true, 'credits'=>$value
			]);
		}

		return $this->render('Payment/invite.html.twig', [
			'form'=>$form->createView()
		]);
	}

}