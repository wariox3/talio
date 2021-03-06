<?php

namespace App\Repository\Inventario;

use App\Entity\Cartera\CarCuentaCobrar;
use App\Entity\Empresa;
use App\Entity\General\GenConfiguracion;
use App\Entity\Inventario\InvContrato;
use App\Entity\Inventario\InvContratoDetalle;
use App\Entity\General\GenDocumento;
use App\Entity\Inventario\InvItem;
use App\Entity\Inventario\InvMovimiento;
use App\Entity\Inventario\InvMovimientoDetalle;
use App\Utilidades\Mensajes;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityRepository;
use Doctrine\Common\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Session\Session;


class InvContratoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvContrato::class);
    }

    public function lista($empresa)
    {
        $session = new Session();
        $queryBuilder = $this->getEntityManager()->createQueryBuilder()->from(InvContrato::class, 'c')
            ->select('c.codigoContratoPk')
            ->addSelect('c.numero')
            ->addSelect('c.fecha')
            ->addSelect('c.referencia')
            ->addSelect('ct.nombreCorto AS cliente')
            ->addSelect('c.estadoAutorizado')
            ->addSelect('c.estadoAprobado')
            ->addSelect('c.estadoAnulado')
            ->leftJoin('c.terceroRel', 'ct')
            ->andWhere('c.codigoEmpresaFk = ' . $empresa);
        if ($session->get('filtroContratoFechaDesde') != null) {
            $queryBuilder->andWhere("c.fecha >= '{$session->get('filtroContratoFechaDesde')} 00:00:00'");
        }
        if ($session->get('filtroContratoFechaHasta') != null) {
            $queryBuilder->andWhere("c.fecha <= '{$session->get('filtroContratoFechaHasta')} 23:59:59'");
        }
        if ($session->get('filtroContratoTercero')) {
            $queryBuilder->andWhere("c.codigoTerceroFk = '{$session->get('filtroContratoTercero')}'");
        }
        $queryBuilder->orderBy("c.codigoContratoPk", 'DESC');
        return $queryBuilder;
    }

    public function listaGenerarFactura($codigoEmpresa)
    {
        $session = new Session();
        $queryBuilder = $this->getEntityManager()->createQueryBuilder()->from(InvContrato::class, 'c')
            ->select('c.codigoContratoPk')
            ->addSelect('c.numero')
            ->addSelect('c.fecha')
            ->addSelect('c.referencia')
            ->addSelect('ct.nombreCorto AS clienteNombre')
            ->addSelect('ct.numeroIdentificacion AS clienteIdentificacion')
            ->addSelect('c.vrSubtotal')
            ->addSelect('c.vrIva')
            ->addSelect('c.vrTotalNeto')
            ->addSelect('c.estadoAutorizado')
            ->addSelect('c.estadoAprobado')
            ->addSelect('c.estadoAnulado')
            ->leftJoin('c.terceroRel', 'ct')
            ->where("c.codigoEmpresaFk = ${codigoEmpresa}");
        $queryBuilder->orderBy("c.codigoContratoPk", 'DESC');
        return $queryBuilder;
    }

    public function generarFacturaTodos($codigoEmpresa)
    {
        $em = $this->getEntityManager();
        $queryBuilder = $em->createQueryBuilder()->from(InvContrato::class, 'c')
            ->select('c.codigoContratoPk')
            ->where("c.codigoEmpresaFk = ${codigoEmpresa}")
            ->andWhere("c.estadoAprobado=1");
        $arContratos = $queryBuilder->getQuery()->getResult();
        foreach ($arContratos as $arContrato) {
            $this->generarFactura($codigoEmpresa, $arContrato['codigoContratoPk']);
        }
        $em->flush();
    }

    public function generarFacturaSeleccionados($codigoEmpresa, $arrSeleccionados)
    {
        $em = $this->getEntityManager();
        if ($arrSeleccionados) {
            foreach ($arrSeleccionados as $codigo) {
                $this->generarFactura($codigoEmpresa, $codigo);
            }
        }
        $em->flush();
    }

    /**
     * @param $codigoEmpresa
     * @param $codigoContrato
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    private function generarFactura($codigoEmpresa, $codigoContrato)
    {
        $em = $this->getEntityManager();
        $arItemInteres = null;
        $arrConfiguracion = $em->getRepository(GenConfiguracion::class)->generarFacturaMasiva($codigoEmpresa);
        if ($arrConfiguracion['generaInteresMora']) {
            $arItemInteres = $em->getRepository(InvItem::class)->find($arrConfiguracion['codigoItemInteresMora']);
        }
        $queryBuilder = $this->getEntityManager()->createQueryBuilder()->from(InvContratoDetalle::class, 'cd')
            ->select('cd.codigoContratoDetallePk')
            ->addSelect('cd.codigoItemFk')
            ->addSelect('cd.cantidad')
            ->addSelect('cd.porcentajeIva')
            ->addSelect('cd.vrPrecio')
            ->addSelect('cd.vrIva')
            ->addSelect('cd.vrSubtotal')
            ->addSelect('cd.vrTotal')
            ->where('cd.codigoContratoFk = ' . $codigoContrato);
        $arContratoDetalles = $queryBuilder->getQuery()->getResult();
        if ($arContratoDetalles) {
            /** @var $arContrato InvContrato */
            $arContrato = $em->getRepository(InvContrato::class)->find($codigoContrato);
            $arDocumento = $em->getRepository(GenDocumento::class)->find('FAC');
            $arFactura = new InvMovimiento();
            $arFactura->setCodigoEmpresaFk($codigoEmpresa);
            $arFactura->setDocumentoRel($arDocumento);
            $arFactura->setTerceroRel($arContrato->getTerceroRel());
            $arFactura->setPlazoPago($arContrato->getTerceroRel()->getPlazoPago());
            $arFactura->setFormaPagoRel($arContrato->getTerceroRel()->getFormaPagoRel());
            $arFactura->setReferencia($arContrato->getReferencia());
            $arFactura->setFecha(new \DateTime('now'));
            $subTotalGeneral = $arContrato->getVrSubtotal();
            $totalBrutoGeneral = $arContrato->getVrTotalBruto();
            $totalNetoGeneral = $arContrato->getVrTotalNeto();

            /** @var $arContratoDetalle InvContratoDetalle */
            foreach ($arContratoDetalles as $arContratoDetalle) {
                $arFacturaDetalle = new InvMovimientoDetalle();
                $arItem = $em->getRepository(InvItem::class)->find($arContratoDetalle['codigoItemFk']);
                $arFacturaDetalle->setItemRel($arItem);
                $arFacturaDetalle->setMovimientoRel($arFactura);
                $arFacturaDetalle->setCodigoEmpresaFk($codigoEmpresa);
                $arFacturaDetalle->setCantidad($arContratoDetalle['cantidad']);
                $arFacturaDetalle->setPorcentajeIva($arContratoDetalle['porcentajeIva']);
                $arFacturaDetalle->setVrPrecio($arContratoDetalle['vrPrecio']);
                $arFacturaDetalle->setVrIva($arContratoDetalle['vrIva']);
                $arFacturaDetalle->setVrSubtotal($arContratoDetalle['vrSubtotal']);
                $arFacturaDetalle->setVrTotal($arContratoDetalle['vrTotal']);
                $em->persist($arFacturaDetalle);
            }
            if ($arrConfiguracion['generaInteresMora']) {
                $vrSaldoMora = $em->getRepository(CarCuentaCobrar::class)->saldo($arContrato->getCodigoTerceroFk());
                if ($vrSaldoMora > 0) {
                    $precio = $vrSaldoMora * $arrConfiguracion['porcentajeInteresMora'] / 100;
                    $arFacturaDetalle = new InvMovimientoDetalle();
                    $arFacturaDetalle->setMovimientoRel($arFactura);
                    $arFacturaDetalle->setItemRel($arItemInteres);
                    $arFacturaDetalle->setCantidad(1);
                    $arFacturaDetalle->setVrPrecio($precio);
                    $arFacturaDetalle->setVrIva(0);
                    $arFacturaDetalle->setVrSubtotal($precio);
                    $arFacturaDetalle->setVrTotal($precio);
                    $em->persist($arFacturaDetalle);
                    $subTotalGeneral += $precio;
                    $totalBrutoGeneral += $precio;
                    $totalNetoGeneral += $precio;
                }
            }

            $arFactura->setVrSubtotal($subTotalGeneral);
            $arFactura->setVrTotalBruto($totalBrutoGeneral);
            $arFactura->setVrTotalNeto($totalNetoGeneral);
            $arFactura->setVrIva($arContrato->getVrIva());
            $arFactura->setEstadoAutorizado(1);
            $em->persist($arFactura);

            $em->getRepository(InvMovimiento::class)->aprobar($arFactura);
        }
    }


    /**
     * @param $arContrato InvContrato
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function liquidar($arContrato)
    {
        $em = $this->getEntityManager();
        $vrSubtotalGlobal = 0;
        $vrTotalBrutoGlobal = 0;
        $vrIvaGlobal = 0;
        $arContratoDetalles = $this->getEntityManager()->getRepository(InvContratoDetalle::class)->findBy(['codigoContratoFk' => $arContrato->getCodigoContratoPk()]);
        foreach ($arContratoDetalles as $arContratoDetalle) {
            $vrSubtotal = $arContratoDetalle->getVrSubtotal();
            $vrSubtotalGlobal += $vrSubtotal;
            $vrTotal = $arContratoDetalle->getVrTotal();
            $vrTotalBrutoGlobal += $vrTotal;
            $vrIva = $arContratoDetalle->getVrIva();
            $vrIvaGlobal += $vrIva;
        }
        $arContrato->setVrSubtotal($vrSubtotalGlobal);
        $arContrato->setVrTotalBruto($vrTotalBrutoGlobal);
        $arContrato->setVrIva($vrIvaGlobal);
        $arContrato->setVrTotalNeto($vrTotalBrutoGlobal);
        $em->persist($arContrato);
        $em->flush();
    }

    /**
     * @param $codigoContrato
     * @return mixed
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function contarDetalles($codigoContrato)
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder()->from(InvContratoDetalle::class, 'cd')
            ->select("COUNT(cd.codigoContratoDetallePk)")
            ->where("cd.codigoContratoFk = {$codigoContrato} ");
        $resultado = $queryBuilder->getQuery()->getSingleResult();
        return $resultado[1];
    }

    /**
     * @param $arContrato InvContrato
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function desautorizar($arContrato)
    {
        if ($arContrato->isEstadoAprobado() == 0) {
            $arContrato->setEstadoAutorizado(0);
            $this->getEntityManager()->persist($arContrato);
            $this->getEntityManager()->flush();

        } else {
            Mensajes::error('El registro ya se encuentra aprobado');
        }
    }

    /**
     * @param $arContrato InvContrato
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function autorizar($arContrato)
    {
        if ($this->getEntityManager()->getRepository(InvContrato::class)->contarDetalles($arContrato->getCodigoContratoPk()) > 0) {
            $arContrato->setEstadoAutorizado(1);
            $this->getEntityManager()->persist($arContrato);
            $this->getEntityManager()->flush();
        } else {
            Mensajes::error("El registro no tiene detalles");
        }
    }
}