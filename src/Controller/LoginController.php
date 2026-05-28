<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class LoginController extends AbstractController
{
    #[Route('/login', name: 'login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Si ya está autenticado, redirigir al inicio
        if ($this->getUser()) {
            return $this->redirectToRoute('inicio');
        }

        // Obtener error de login si existe
        $error = $authenticationUtils->getLastAuthenticationError();

        // Último username ingresado
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('login/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'logout')]
    public function logout(): void
    {
        // Symfony maneja el logout automáticamente
        throw new \LogicException('Este método está vacío - Symfony intercepta el logout automáticamente.');
    }
}