<?php
namespace App\Controller\wolframio;

use App\Utilidades\Mensajes;
use App\Utilidades\Softgic;
use App\Utilidades\Wolframio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CuentaController extends AbstractController
{
    #[Route('/wolframio/cuenta/lista', name: 'wolframio_cuenta_lista')]
    public function lista(Request $request, Wolframio $wolframio): Response
    {
        $form = $this->createFormBuilder()->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

        }
        if ($request->get('OpCrear')) {
            $codigo = $request->get('OpCrear');
            $datos = [
                'cuentaId' => $codigo
            ];
            $respuesta = $wolframio->consumoPost('api/cuenta/softgic/suscriptor/nuevo', $datos);
        }
        if ($request->get('OpCrearEmpleador')) {
            $codigo = $request->get('OpCrearEmpleador');
            $datos = [
                'cuentaId' => $codigo
            ];
            $respuesta = $wolframio->consumoPost('api/cuenta/softgic/empleador/nuevo', $datos);
        }
        if ($request->get('OpHabilitar')) {
            $codigo = $request->get('OpHabilitar');
            $datos = [
                'cuentaId' => $codigo
            ];
            $respuesta = $wolframio->consumoPost('api/cuenta/softgic/suscriptor/habilitar', $datos);
        }
        if ($request->get('OpHabilitarNomina')) {
            $codigo = $request->get('OpHabilitarNomina');
            $datos = [
                'cuentaId' => $codigo
            ];
            $respuesta = $wolframio->consumoPost('api/cuenta/softgic/empleador/actualizar', $datos);
            $respuesta = $wolframio->consumoPost('api/cuenta/softgic/empleador/habilitar', $datos);
        }
        $cuentas = [];
        $datos = [];
        $respuesta = $wolframio->consumoPost('api/cuenta/lista', $datos);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $cuentas = $arrDatos['cuentas'];
        }
        return $this->render('wolframio/cuenta/lista.html.twig', [
            'cuentas' => $cuentas,
            'form' => $form->createView()]);
    }

    #[Route('/wolframio/cuenta/resolucion/{suscriptor}', name: 'wolframio_cuenta_resolucion')]
    public function resolucion(Request $request, Softgic $softgic, $suscriptor): Response
    {
        $resoluciones = [];
        $respuesta = $softgic->consultaSuscriptor($suscriptor);
        if(!$respuesta['error']) {
            $resoluciones = $respuesta['resoluciones'];
        }
        return $this->render('wolframio/cuenta/resoluciones.html.twig', [
            'resoluciones' => $resoluciones]);
    }

    #[Route('/wolframio/cuenta/suscriptor/{suscriptor}', name: 'wolframio_cuenta_suscriptor')]
    public function suscriptor(Request $request, Softgic $softgic, $suscriptor): Response
    {
        $datosSuscriptor = [];
        $respuesta = $softgic->consultaSuscriptor($suscriptor);
        if(!$respuesta['error']) {
            $datosSuscriptor = $respuesta['suscriptor'];
        }
        $form = $this->createFormBuilder()
            ->add('Documento', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['Documento']:''])
            ->add('Dv', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['Dv']:''])
            ->add('RazonSocial', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['RazonSocial']:''])
            ->add('Direccion', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['Direccion']:''])
            ->add('CodigoPostal', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['CodigoPostal']:''])
            ->add('CodigoCiudad', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['CodigoCiudad']:'', 'disabled' => true])
            ->add('NombreCiudad', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['NombreCiudad']:'', 'disabled' => true])
            ->add('CodigoDepartamento', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['CodigoDepartamento']:'', 'disabled' => true])
            ->add('NombreDepartamento', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['NombreDepartamento']:'', 'disabled' => true])
            ->add('Obligaciones', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['Obligaciones']:''])
            ->add('Correo', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['Correo']:''])
            ->add('Telefono', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['Telefono']:''])
            ->add('CodigoPersona', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['CodigoPersona']:'', 'required' => true])
            ->add('TipoPersona', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['TipoPersona']:'', 'disabled' => true])
            ->add('CodigoRegimen', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['CodigoRegimen']:''])
            ->add('NombreRegimen', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['NombreRegimen']:'', 'disabled' => true])
            ->add('setPruebas', TextType::class, ['data' => $datosSuscriptor?$datosSuscriptor['TestPruebas']:''])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
        }
        return $this->render('wolframio/cuenta/suscriptor.html.twig', [
            'aliado' => $datosSuscriptor?$datosSuscriptor['DocumentoAliado']:'',
            'aliadoNombre' => $datosSuscriptor?$datosSuscriptor['NombreAliado']:'',
            'form' => $form->createView()]);
    }

    #[Route('/wolframio/cuenta/empleador/{empleador}', name: 'wolframio_cuenta_empleador')]
    public function empleador(Request $request, Softgic $softgic, $empleador): Response
    {
        $datosEmpleador = [];
        $respuesta = $softgic->consultaEmpleador($empleador);
        if(!$respuesta['error']) {
            $datosEmpleador = $respuesta['empleador'];
        }
        $form = $this->createFormBuilder()
            ->add('NIT', TextType::class, ['data' => $datosEmpleador?$datosEmpleador['NIT']:''])
            ->add('DV', TextType::class, ['data' => $datosEmpleador?$datosEmpleador['DV']:''])
            ->add('RazonSocial', TextType::class, ['data' => $datosEmpleador?$datosEmpleador['RazonSocial']:''])
            ->add('Direccion', TextType::class, ['data' => $datosEmpleador?$datosEmpleador['Direccion']:''])
            ->add('MunicipioCiudad', TextType::class, ['data' => $datosEmpleador?$datosEmpleador['MunicipioCiudad']:'', 'disabled' => true])
            ->add('DepartamentoEstado', TextType::class, ['data' => $datosEmpleador?$datosEmpleador['DepartamentoEstado']:'', 'disabled' => true])
            ->add('TestId', TextType::class, ['data' => $datosEmpleador?$datosEmpleador['TestId']:''])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

        }
        return $this->render('wolframio/cuenta/empleador.html.twig', [
            'form' => $form->createView()]);
    }
}