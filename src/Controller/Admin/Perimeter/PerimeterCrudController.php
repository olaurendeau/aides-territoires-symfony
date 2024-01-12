<?php

namespace App\Controller\Admin\Perimeter;

use App\Controller\Admin\AtCrudController;
use App\Entity\Perimeter\Perimeter;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;

class PerimeterCrudController extends AtCrudController
{
    public static function getEntityFqcn(): string
    {
        return Perimeter::class;
    }

    
    public function configureFields(string $pageName): iterable
    {
        $scaleChoices = [];
        foreach (Perimeter::SCALES_TUPLE as $scale) {
            $scaleChoices[$scale['name']] = $scale['scale'];
        }
        yield IdField::new('id')->onlyOnIndex();
        yield ChoiceField::new('scale', 'Echelle')
        ->setChoices($scaleChoices)
        ;
        yield TextField::new('name', 'Nom du périmètre');
        yield TextField::new('unaccentedName', 'Nom du périmètre sans accents')
        ->onlyOnForms();
        yield TextField::new('code', 'Code')
        ->onlyOnForms()
        ->setHelp('Usage interne uniquement, non pertinent pour les périmètres Ad-hoc.');
        yield BooleanField::new('manuallyCreated', 'Créer manuellement')
        ->setFormTypeOption('attr', [
            'readonly' => true
        ]);
        yield BooleanField::new('isObsolete', 'Ce périmètre n’existe plus');

        yield DateTimeField::new('timeObsolete', 'Date d\'obsolescence')
        ->onlyOnForms()
        ->setHelp('Date de mise à jour des périmètres à laquelle ce périmètre ne figurait plus dans les sources officielles');
        yield BooleanField::new('isVisibleToUsers', 'Le périmètre est visible pour les utilisateurs');
        yield DateTimeField::new('timeCreate', 'Date de création')
        ->onlyOnForms()
        ->setFormTypeOption('attr', ['readonly' => true]);
        yield DateTimeField::new('timeUpdate', 'Date de modification')
        ->onlyOnForms()
        ->setFormTypeOption('attr', ['readonly' => true]);

        yield FormField::addFieldset('Identifiants');
        yield TextField::new('insee', 'Code Insee')
        ->onlyOnForms()
        ->setHelp('Identifiant officiel défini dans le Code officiel géographique');
        yield TextField::new('siren', 'Numéro Siren')
        ->onlyOnForms()
        ->setHelp('Identifiant officiel à 9 chiffres défini dans la base SIREN');
        yield TextField::new('siret', 'Numéro Siret')
        ->onlyOnForms()
        ->setHelp('Identifiant officiel à 14 chiffres défini dans la base SIREN');
        yield ArrayField::new('zipcodes', 'Codes postaux')
        ->onlyOnForms();


        yield FormField::addFieldset('Situation');
        yield TextField::new('continent', 'Continent')
        ->onlyOnForms()
        ->setHelp('Code ISO');
        yield TextField::new('country', 'Pays')
        ->onlyOnForms()
        ->setHelp('Code ISO');
        yield ArrayField::new('regions', 'Régions')
        ->onlyOnForms();
        yield ArrayField::new('departments', 'Départements')
        ->onlyOnForms();
        yield TextField::new('epci', 'EPCI')
        ->onlyOnForms();
        yield TextField::new('basin', 'Bassin hydrographique')
        ->onlyOnForms()
        ->setHelp('Code Sandre');
        yield BooleanField::new('isOverseas', 'En outre-mer')
        ->onlyOnForms();
        yield NumberField::new('latitude', 'Latitude')
        ->onlyOnForms();
        yield NumberField::new('longitude', 'Longitude')
        ->onlyOnForms();
        
        yield FormField::addFieldset('Données');
        yield NumberField::new('population', 'Population')
        ->onlyOnForms();
        yield NumberField::new('surface', 'Superficie')
        ->onlyOnForms()
        ->setHelp('Superficie en hectares');
        yield TextField::new('densityTypology', 'Typologie')
        ->onlyOnForms()
        ->setHelp('définit le statut d’une commune rurale ou urbaine');


        yield FormField::addFieldset('Compteurs');
        yield IntegerField::new('backersCount', 'Nombre de porteurs')
        ->onlyOnForms()
        ->setFormTypeOption('attr', [
            'readonly' => true
        ]);
        yield IntegerField::new('programsCount', 'Nombre de programmes')
        ->onlyOnForms()
        ->setFormTypeOption('attr', [
            'readonly' => true
        ]);
        yield IntegerField::new('categoriesCount', 'Nombre de catégories')
        ->onlyOnForms()
        ->setFormTypeOption('attr', [
            'readonly' => true
        ]);
        yield IntegerField::new('liveAidsCount', 'Nombre d\'aides lives')
        ->onlyOnForms()
        ->setFormTypeOption('attr', [
            'readonly' => true
        ]);
        yield IntegerField::new('projectsCount', 'Nombre de projets subventionnés')
        ->onlyOnForms()
        ->setFormTypeOption('attr', [
            'readonly' => true
        ]);

        yield FormField::addFieldset('Données de périmètres')->renderCollapsed();
        yield CollectionField::new('perimeterDatas', 'Données')
        ->onlyOnForms()
        ->setEntryIsComplex()
        ->useEntryCrudForm(PerimeterDataCrudController::class)
        ;

    }

    public function  configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
        ->overrideTemplate('crud/edit', 'admin/perimeter/edit.html.twig')  
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        // action pour afficher le qrCode
        $combine = Action::new('combine', 'Combiner')
            ->setHtmlAttributes(['title' => 'Combiner'])
            ->linkToRoute('admin_perimeter_combine', function (Perimeter $entity) {
                return [
                    'id' => $entity->getId()
                ];
            })
        ;

        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, $combine)
            ->add(Crud::PAGE_EDIT, $combine)
        ;
    }
}
