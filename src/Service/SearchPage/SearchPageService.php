<?php

namespace App\Service\SearchPage;

use App\Entity\Search\SearchPage;
use App\Entity\Search\SearchPageLock;
use App\Entity\User\User;
use App\Service\Organization\OrganizationService;
use App\Service\User\UserService;
use Doctrine\Persistence\ManagerRegistry;

class SearchPageService
{
    public function __construct(
        private UserService $userService,
        private OrganizationService $organizationService,
        private ManagerRegistry $managerRegistry
    )
    {
    }

    public function userCanViewEdit(SearchPage $searchPage, User $user)
    {
        // si c'est l'auteur ou un admin
        if ($searchPage->getAdministrator() == $user || $this->userService->isUserGranted($user, User::ROLE_ADMIN)) {
            return true;
        }

        // si il appartiens à l'organisation
        if ($searchPage->getOrganization()) {
            foreach ($searchPage->getOrganization()->getOrganizationAccesses() as $organizationAccess) {
                if ($organizationAccess->getUser() == $user) {
                    return true;
                }
            }
        }

        return false;
    }

    public function canUserLock(SearchPage $searchPage, User $user): bool
    {
        if (!$searchPage->getOrganization()) {
            return false;
        }

        if ($this->organizationService->canEditPortal($user, $searchPage->getOrganization())) {
            return true;
        }

        return false;
    }
    
    public function getLock(SearchPage $searchPage): ?SearchPageLock
    {
        foreach ($searchPage->getSearchPageLocks() as $searchPageLock) {
            return $searchPageLock;
        }

        return null;
    }
    
    public function isLockedByAnother(SearchPage $searchPage, User $user): bool
    {
        $now = new \DateTime(date('Y-m-d H:i:s'));
        $minutesMax = 5;
        foreach ($searchPage->getSearchPageLocks() as $searchPageLock) {
            // si le lock a plus de 5 min, on le supprime
            if ($searchPageLock->getTimeStart() < $now->sub(new \DateInterval('PT'.$minutesMax.'M'))) {
                $this->managerRegistry->getManager()->remove($searchPageLock);
                $this->managerRegistry->getManager()->flush();
                continue;
            }

            if ($searchPageLock->getUser() != $user) {
                return true;
            }
        }
        return false;
    }

    public function isLocked(SearchPage $searchPage): bool
    {
        return count($searchPage->getSearchPageLocks()) > 0;
    }
    
    public function lock(SearchPage $searchPage, User $user): void
    {
        try {
            // vérifie que l'aide n'est pas déjà lock
            if (count($searchPage->getSearchPageLocks()) == 0) {
                $searchPageLock = new SearchPageLock();
                $searchPageLock->setSearchPage($searchPage);
                $searchPageLock->setUser($user);
                $this->managerRegistry->getManager()->persist($searchPageLock);
                $this->managerRegistry->getManager()->flush();
            } else {
                $searchPageLock = (isset($searchPage->getSearchPageLocks()[0]) && $searchPage->getSearchPageLocks()[0] instanceof SearchPageLock)
                            ? $searchPage->getSearchPageLocks()[0]
                            : null;
                // on met à jour le lock si le user et l'aide sont bien les mêmes
                if ($searchPageLock && $searchPageLock->getUser() == $user && $searchPageLock->getSearchPage() == $searchPage) {
                    $searchPageLock->setTimeStart(new \DateTime(date('Y-m-d H:i:s')));
                    $searchPageLock->setSearchPage($searchPage);
                    $searchPageLock->setUser($user);
                    $this->managerRegistry->getManager()->persist($searchPageLock);
                    $this->managerRegistry->getManager()->flush();
                }
            }
        } catch (\Exception $e) {
        }
    }

    public function unlock(SearchPage $searchPage): void
    {
        foreach ($searchPage->getSearchPageLocks() as $searchPageLock) {
            $this->managerRegistry->getManager()->remove($searchPageLock);
        }
        $this->managerRegistry->getManager()->flush();
    }
}