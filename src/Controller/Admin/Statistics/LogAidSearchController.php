<?php

namespace App\Controller\Admin\Statistics;

use App\Controller\Admin\DashboardController;
use App\Entity\Log\LogAidSearch;
use App\Form\Admin\Filter\DateRangeType;
use App\Repository\Log\LogAidSearchRepository;
use App\Repository\Perimeter\PerimeterRepository;
use App\Repository\Reference\ProjectReferenceRepository;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use Psr\Http\Message\RequestInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class LogAidSearchController extends AbstractController
{
    public function __construct(
        private LogAidSearchRepository $logAidSearchRepository,
        private RequestStack $requestStack
    )
    {
    }

    #[Route('/admin/statistics/log/aid-search', name: 'admin_statistics_log_aid_search')]
    public function blogDashboard(
        AdminContext $adminContext,
        LogAidSearchRepository $logAidSearchRepository,
        ProjectReferenceRepository $projectReferenceRepository
    )
    {
        // dates par défaut
        $dateMin = new \DateTime('-1 week');
        $dateMax = new \DateTime();

        // formulaire de filtre
        $formDateRange = $this->createForm(DateRangeType::class);
        $formDateRange->handleRequest($adminContext->getRequest());
        if ($formDateRange->isSubmitted()) {
            if ($formDateRange->isValid()) {
                $dateMin = $formDateRange->get('dateMin')->getData();
                $dateMax = $formDateRange->get('dateMax')->getData();
            }
        } else {
            $formDateRange->get('dateMin')->setData($dateMin);
            $formDateRange->get('dateMax')->setData($dateMax);
        }

        // les recherches qui donnent peu de résultats
        $logAidSearchs = $logAidSearchRepository->findKeywordSearchWithFewResults([
            'dateCreateMin' => $dateMin,
            'dateCreateMax' => $dateMax,
            'hasSearch' => true,
            'resultsCountMax' => 10,
            'orderBy' => [
                'sort' => 'l.timeCreate',
                'order' => 'DESC'
            ]
        ]);

        $queriesByLogId = [];
        /** @var LogAidSearch $logAidSearch */
        foreach ($logAidSearchs as $logAidSearch) {
            $queriesByLogId[$logAidSearch->getId()] = explode('&', $logAidSearch->getQuerystring());
        }

        // regarde si il y a un projet référent correspondant à la recherche
        $projectReferences = $projectReferenceRepository->findAll();
        $projectReferencesByLogId = [];
        foreach ($logAidSearchs as $logAidSearch) {
            $projectReferencesByLogId[$logAidSearch->getId()] = null;
            foreach ($projectReferences as $projectReference) {
                if ($projectReference->getName() == $logAidSearch->getSearch()) {
                    $projectReferencesByLogId[$logAidSearch->getId()] = $projectReference;
                    break;
                }
            }
        }

        return $this->render('admin/statistics/log/aid-search.html.twig', [
            'formDateRange' => $formDateRange,
            'dateMin' => $dateMin,
            'dateMax' => $dateMax,
            'logAidSearchs' => $logAidSearchs,
            'queriesByLogId' => $queriesByLogId,
            'projectReferencesByLogId' => $projectReferencesByLogId
        ]);
    }


    #[Route('/admin/statistics/log/aid-search/missing-perimeters', name: 'admin_statistics_log_aid_search_missing_perimeters')]
    public function missingPerimeters(
        LogAidSearchRepository $logAidSearchRepository,
        AdminContext $adminContext,
        ChartBuilderInterface $chartBuilderInterface,
        PerimeterRepository $perimeterRepository
    ): Response
    {
        // dates par défaut
        $dateMin = new \DateTime('-1 week');
        $dateMax = new \DateTime();

        // formulaire de filtre
        $formDateRange = $this->createForm(DateRangeType::class);
        $formDateRange->handleRequest($adminContext->getRequest());
        if ($formDateRange->isSubmitted()) {
            if ($formDateRange->isValid()) {
                $dateMin = $formDateRange->get('dateMin')->getData();
                $dateMax = $formDateRange->get('dateMax')->getData();
            }
        } else {
            $formDateRange->get('dateMin')->setData($dateMin);
            $formDateRange->get('dateMax')->setData($dateMax);
        }

        // les départements (pour affichage)
        $departments = $perimeterRepository->getDepartments();
        
        $departmentsByCode = [];
        foreach ($departments as $department) {
            $departmentsByCode[$department->getCode()] = $department;
        }

        // les stats
        $logAidSearchs = $logAidSearchRepository->getSearchOnPerimeterWithoutOrganization([
            'dateCreateMin' => $dateMin,
            'dateCreateMax' => $dateMax
        ]);

        $logAidSearchsByDept = [];
        foreach ($logAidSearchs as $logAidSearch) {
            $dept = ($logAidSearch['insee']) ? substr($logAidSearch['insee'], 0, 2) : 0;
            if (!isset($logAidSearchsByDept[$dept])) {
                $logAidSearchsByDept[$dept] = [
                    'dept' => $dept,
                    'count' => 0,
                    'fullName' => isset($departmentsByCode[$dept]) ? $departmentsByCode[$dept]->getName() : sprintf('Inconnu (%s)', $dept)
                ];
            }
            $logAidSearchsByDept[$dept]['count']++;
        }

        if (!empty($logAidSearchsByDept)) {
            // graphique repartition par département
            $chartByDept = $chartBuilderInterface->createChart(Chart::TYPE_PIE);
            foreach ($logAidSearchsByDept as $logAidSearchByDept) {
                $labels[] = $logAidSearchByDept['fullName'];
                $datas[] = $logAidSearchByDept['count'];
                $colors[] = 'rgb('.rand(0, 255).', '.rand(0, 255).', '.rand(0, 255).')';
            }
            $chartByDept->setData([
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Nombre de recherche',
                        'backgroundColor' => $colors,
                        'data' => $datas,
                    ],
                ],
            ]);

            $chartByDept->setOptions([
                'responsive' => true,
                'plugins' => [
                    'legend' => [
                        'position' => 'top',
                    ],
                    'title' => [
                        'display' => true,
                        'text' => 'Nombre de recherche sur périmètre sans organisation par département',
                    ],
                ],
            ]);
        }

        // rendu template
        return $this->render('admin/statistics/log/aid-search-missing-perimeters.html.twig', [
            'formDateRange' => $formDateRange,
            'dateMin' => $dateMin,
            'dateMax' => $dateMax,
            'chartByDept' => $chartByDept ?? null,
        ]);
    }

    #[Route('/admin/statistics/log/aid-search/missing-perimeters/export', name: 'admin_statistics_log_aid_search_missing_perimeters_export')]
    public function exportRegistrationByMonth(
    ): StreamedResponse
    {
        $response = new StreamedResponse();
        $response->setCallback(function () {

            $dateMin = $this->requestStack->getCurrentRequest()->get('dateMin')
                    ? new \DateTime($this->requestStack->getCurrentRequest()->get('dateMin'))
                    : new \DateTime(date('Y-m-d', strtotime('-1 week')));
            $dateMax = $this->requestStack->getCurrentRequest()->get('dateMax')
                    ? new \DateTime($this->requestStack->getCurrentRequest()->get('dateMax'))
                    : new \DateTime(date('Y-m-d'));

                    // options CSV
            $options = new \OpenSpout\Writer\CSV\Options();
            $options->FIELD_DELIMITER = ';';
            $options->FIELD_ENCLOSURE = '"';

            // writer
            $writer = new \OpenSpout\Writer\CSV\Writer($options);

            // ouverture fichier
            $now = new \DateTime(date('Y-m-d H:i:s'));
            $writer->openToBrowser('export_recherche_perimetre_sans_organisation_'.$now->format('d_m_Y').'.csv');

            // entêtes
            $cells = [
                Cell::fromValue('Id périmètre'),
                Cell::fromValue('Périmètre'),
                Cell::fromValue('Code insee'),
            ];
            $singleRow = new Row($cells);
            $writer->addRow($singleRow);

            // les inscriptions
            $logAidSearchs = $this->logAidSearchRepository->getSearchOnPerimeterWithoutOrganization([
                'dateCreateMin' => $dateMin,
                'dateCreateMax' => $dateMax
            ]);
            foreach ($logAidSearchs as $logAidSearch) {
                // ajoute ligne par ligne
                $cells = [
                    Cell::fromValue($logAidSearch['id']),
                    Cell::fromValue($logAidSearch['name']),
                    Cell::fromValue($logAidSearch['insee'])
                ];

                $singleRow = new Row($cells);
                $writer->addRow($singleRow);
            }

            // fermeture fichier
            $writer->close();
        });

        return $response;
    }
}