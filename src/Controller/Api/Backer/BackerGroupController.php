<?php

namespace App\Controller\Api\Backer;

use App\Controller\Api\ApiController;
use App\Entity\Backer\BackerGroup;
use App\Repository\Backer\BackerGroupRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
class BackerGroupController extends ApiController
{
    #[Route('/api/backer-groups/', name: 'api_backer_backer_groups', priority: 5)]
    public function index(
        BackerGroupRepository $backerGroupRepository
    ): JsonResponse {
        $params = [];

        // requete pour compter sans la pagination
        $count = $backerGroupRepository->countCustom($params);

        // requete pour les résultats avec la pagination
        $params['firstResult'] = ($this->getPage() - 1) * $this->getItemsPerPage();
        $params['maxResults'] = $this->getItemsPerPage();

        $results = $backerGroupRepository->findCustom($params);

        // on serialize pour ne garder que les champs voulus
        $results = $this->serializerInterface->serialize(
            $results,
            static::SERIALIZE_FORMAT,
            ['groups' => BackerGroup::API_GROUP_LIST]
        );

        // le retour
        $data = [
            'count' => $count,
            'previous' => $this->getPrevious(),
            'next' => $this->getNext($count),
            'results' => json_decode($results)
        ];

        // la réponse
        $response =  new JsonResponse($data, 200, [], false);
        // pour eviter que les urls ne soient ecodées
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES);
        return $response;
    }
}
