<?php

namespace Plugin\DataMigration43\Form\Type\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

class ConfigType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('import_file', FileType::class, [
                'label' => false,
                'mapped' => false,
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'ファイルを選択してください。']),
                    new File([
                        'mimeTypes' => ['application/zip', 'application/x-tar', 'application/x-gzip', 'application/gzip'],
                        'mimeTypesMessage' => 'zipファイル、tarファイル、tar.gzファイルのいずれかをアップロードしてください。',
                    ]),
                ],
            ])->add('customer_order_only', CheckboxType::class, [
                'label' => '会員と受注データのみ移行する(一度データ移行を実施している必要があります)',
                'required' => false,
            ])
            ->add('auth_magic', TextType::class, [
                'label' => 'AUTH_MAGIC',
                'required' => true,
                //'placeholder' => '',
                'attr' => [
                    'placeholder' => "旧サイトのAUTH_MAGICを入力してください。",
                ],
            ])
        ;
    }
}
