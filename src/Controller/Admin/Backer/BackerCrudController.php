<?php

namespace App\Controller\Admin\Backer;

use App\Controller\Admin\AtCrudController;
use App\Controller\Admin\Filter\Backer\HasNoOrganizationFilter;
use App\Entity\Backer\Backer;
use App\Field\TextLengthCountField;
use App\Field\TrumbowygField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class BackerCrudController extends AtCrudController
{
    public static function getEntityFqcn(): string
    {
        return Backer::class;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('active')
            ->add('isSpotlighted')
            ->add(HasNoOrganizationFilter::new('hasNoOrganization', 'Pas de structure associée'))
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        //-------------------------------------------------------
        yield FormField::addTab('Général');

        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', 'Nom');
        yield TextField::new('slug', 'Slug')
            ->setFormTypeOption('attr', ['readonly' => true, 'autocomplete' => 'off'])
            ->setHelp('Laisser vide pour autoremplir.')
            ->hideOnIndex();
        yield BooleanField::new('active', 'Actif')
            ->setHelp('Un porteur d’aides actif est visible sur le site');
        yield AssociationField::new('organizations', 'Organizations')
            ->autocomplete(true)
            ->formatValue(function ($value, $entity) {
                $return = '';
                foreach ($entity->getOrganizations() as $organization) {
                    $return .= '- ' . $organization->getName() . '<br />';
                }
                return $return;
            })
            ->setFormTypeOptions([
                'by_reference' => false,
            ]);

        yield AssociationField::new('backerGroup', 'Groupe de porteurs')
            ->setFormTypeOption('choice_label', 'name')
            ->hideOnIndex();

        //-------------------------------------------------------
        yield FormField::addTab('Description');


        yield TrumbowygField::new('description', 'Description')
            ->onlyOnForms();
        yield TrumbowygField::new('backerType', 'Type de porteur')
            ->onlyOnForms();
        yield TrumbowygField::new('projectsExamples', 'Exemples de projets accompagnés par le porteur')
            ->onlyOnForms();
        yield TrumbowygField::new('internalOperation', 'Mode de fonctionnement interne pour obtenir une aide')
            ->onlyOnForms();
        yield TrumbowygField::new('contact', 'Contact')
            ->onlyOnForms();
        yield TrumbowygField::new('usefulLinks', 'Liens utiles')
            ->onlyOnForms();

        //-------------------------------------------------------
        yield FormField::addTab('Divers');

        yield ImageField::new('logoFile', 'Logo du porteur')
            ->setHelp('Évitez les fichiers trop lourds. Préférez les fichiers SVG.')
            ->setUploadDir($this->fileService->getUploadTmpDirRelative())
            ->setBasePath($this->paramService->get('cloud_image_url'))
            ->setUploadedFileNamePattern(Backer::FOLDER . '/[slug]-[timestamp].[extension]')
            ->setFormTypeOption('upload_new', function (UploadedFile $file, string $uploadDir, string $fileName) {
                $this->imageService->sendUploadedImageToCloud($file, Backer::FOLDER, $fileName);
                $this->getContext()->getEntity()->getInstance()->setLogo($fileName);
            })
            ->onlyOnForms();
        yield BooleanField::new('deleteLogo', 'Supprimer le fichier actuel')
            ->onlyWhenUpdating();

        yield UrlField::new('externalLink', 'Lien externe')
            ->setHelp('L’URL externe vers laquelle renvoie un clic sur le logo du porteur');

        yield AssociationField::new('perimeter', 'Périmètre')
            ->autocomplete();

        yield BooleanField::new('isCorporate', 'Porteur d’aides privé');
        yield BooleanField::new('isSpotlighted', 'Le porteur est-il mis en avant ?')
            ->setHelp('Si le porteur est mis en avant, son logo apparaît sur la page d’accueil');

        //-------------------------------------------------------
        yield FormField::addTab('SEO');


        yield FormField::addFieldset('SEO');
        yield TextLengthCountField::new('metaTitle', 'Titre (balise meta)')
            ->setHelp(
                'Le titre qui sera affiché dans les SERPs. '
                . 'Il est recommandé de le garder < 60 caractères. '
                . 'Laissez vide pour réutiliser le nom du porteur d’aides.'
            )
            ->setFormTypeOption('attr', ['maxlength' => 255])
            ->onlyOnForms();
        yield TextLengthCountField::new('metaDescription', 'Description (balise meta)')
            ->setHelp('Sera affichée dans les SERPs. À garder < 120 caractères.')
            ->setFormTypeOption('attr', ['maxlength' => 255])
            ->onlyOnForms();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->overrideTemplate('crud/edit', 'admin/backer/edit.html.twig')
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        // action pour afficher le qrCode
        $displayOnFront = Action::new('displayOnFront', 'Afficher sur le site', 'far fa-eye')
            ->setHtmlAttributes(['title' => 'Afficher sur le site', 'target' => '_blank']) // titre
        ;

        //set the link using a string or a callable (function like its being used here)
        $displayOnFront->linkToUrl(function ($entity) {
            return $this->generateUrl(
                'app_backer_details',
                ['id' => $entity->getId(), 'slug' => $entity->getSlug()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        });

        $exportCsvAction = $this->getExportCsvAction();
        $exportXlsxAction = $this->getExportXlsxAction();

        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, $displayOnFront)
            ->add(Crud::PAGE_EDIT, $displayOnFront)
            ->add(Crud::PAGE_INDEX, $exportCsvAction)
            ->add(Crud::PAGE_INDEX, $exportXlsxAction)
        ;
    }
}
