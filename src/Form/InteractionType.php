<?php

namespace App\Form;

use App\Entity\Artifact;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ORM\EntityRepository;


class InteractionType extends AbstractType {
	public function getName() {
		return 'interaction';
	}

	public function configureOptions(OptionsResolver $resolver) {
		$resolver->setDefaults(array(
			'intention'       => 'interaction_12331',
			'multiple'	=> false,
			'settlementcheck' => false,
			'required'	=> true,
		));
		$resolver->setRequired(['action', 'maxdistance', 'me']);
	}

	public function buildForm(FormBuilderInterface $builder, array $options) {
		$me = $options['me'];
		$maxdistance = $options['maxdistance'];
		$settlementcheck = $options['settlementcheck'];
		$builder->add('target', 'entity', array(
			'label'=>'interaction.'.$options['action'].'.name',
			'placeholder'=>$options['multiple']?'character.none':null,
			'multiple'=>$options['multiple'],
			'expanded'=>true,
			'required'=>$options['multiple'],
			'class'=>'BM2SiteBundle:Character', 'choice_label'=>'name', 'query_builder'=>function(EntityRepository $er) use ($me, $maxdistance, $settlementcheck) {
				$qb = $er->createQueryBuilder('c');
				$qb->from('BM2SiteBundle:Character', 'me');
				$qb->where('c.alive = true');
				$qb->andWhere('c.prisoner_of IS NULL');
				$qb->andWhere('c.system NOT LIKE :gm OR c.system IS NULL')->setParameter('gm', 'GM');
				$qb->andWhere('me = :me')->andWhere('c != me')->setParameter('me', $me);
				if ($maxdistance) {
					$qb->andWhere('ST_Distance(me.location, c.location) < :maxdistance')->setParameter('maxdistance', $maxdistance);
				}
				if ($settlementcheck) {
					if (!$me->getInsideSettlement()) {
						// if I am not inside a settlement, I can only attack others who are outside as well
						$qb->andWhere('c.inside_settlement IS NULL');
					}
				}
				$qb->orderBy('c.name', 'ASC');
				return $qb;
		}));

		$method = $options['action']."Fields";
		if (method_exists(__CLASS__, $method)) {
			$this->$method($builder, $options);
		}

		$builder->add('submit', SubmitType::class, array('label'=>'interaction.'.$options['action'].'.submit'));
	}

	private function messageFields(FormBuilderInterface $builder) {
		$builder->add('subject', TextType::class, array(
			'label' => 'interaction.message.subject',
			'required' => true
		));
		$builder->add('body', TextareaType::class, array(
			'label' => 'interaction.message.body',
			'required' => true,
			'empty_data' => '(no message)'
		));
	}

	private function grantFields(FormBuilderInterface $builder) {
		$builder->add('withrealm', CheckboxType::class, array(
			'required' => false,
			'label' => 'control.grant.withrealm',
			'attr' => array('title'=>'control.grant.withrealm2'),
			'translation_domain' => 'actions'
		));
		$builder->add('keepclaim', CheckboxType::class, array(
			'required' => false,
			'label' => 'control.grant.keepclaim',
			'attr' => array('title'=>'control.grant.keeprealm2'),
			'translation_domain' => 'actions'
		));
	}

	private function givegoldFields(FormBuilderInterface $builder) {
		$builder->add('amount', IntegerType::class, array(
			'required' => true,
			'label' => 'interaction.givegold.amount',
		));
	}

	private function giveartifactFields(FormBuilderInterface $builder, array $options) {
		$me = $options['me'];
		$builder->add('artifact', EntityType::class, array(
			'required' => true,
			'label' => 'interaction.giveartifact.which',
			'class'=>Artifact::class,
			'property'=>'name',
			'query_builder'=>function(EntityRepository $er) use ($me) {
				return $er->createQueryBuilder('a')->where('a.owner = :me')->setParameter('me', $me);
			}
		));
	}

}
