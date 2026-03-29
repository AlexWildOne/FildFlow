<?php

namespace App\Admin;

use App\Entity\CampaignLocation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Web\Controller;

/**
 * @Route("/admin/campaign-locations")
 */
class CampaignLocations
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @Route("/", methods={"GET"})
     */
    public function index(Request $request): Response
    {
        // Code to list campaign locations
    }

    /**
     * @Route("/{id}", methods={"GET"})
     */
    public function show(int $id): Response
    {
        // Code to show a specific campaign location
    }

    // Additional methods for create, update, delete, etc.
}