<?php

namespace App\Form\Admin\Filter\Aid;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AidAuthorFilterType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'attr' => [
                'placeholder' => 'Email auteur',
            ],
        ]);
    }

    public function getParent()
    {
        return TextType::class;
    }
}