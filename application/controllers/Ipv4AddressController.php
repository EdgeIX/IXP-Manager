<?php

/*
 * Copyright (C) 2009-2012 Internet Neutral Exchange Association Limited.
 * All Rights Reserved.
 *
 * This file is part of IXP Manager.
 *
 * IXP Manager is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, version v2.0 of the License.
 *
 * IXP Manager is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License v2.0
 * along with IXP Manager.  If not, see:
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */


/**
 * Controller: Manage IPv4 Addresses
 *
 * @author     Barry O'Donovan <barry@opensolutions.ie>
 * @category   INEX
 * @package    INEX_Controller
 * @copyright  Copyright (c) 2009 - 2012, Internet Neutral Exchange Association Ltd
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class Ipv4AddressController extends INEX_Controller_FrontEnd
{
    /**
     * This function sets up the frontend controller
     */
    protected function _feInit()
    {
        $this->assertPrivilege( \Entities\User::AUTH_SUPERUSER );
    
        $this->view->feParams = $this->_feParams = (object)[
            'entity'        => '\\Entities\\IPv4Address',
            'pagetitle'     => 'IPv4 Addresses',
        
            'titleSingular' => 'IPv4 Address',
            'nameSingular'  => 'an IPv4 address',
        
            'defaultAction' => 'list',                    // OPTIONAL; defaults to 'list'
            
            'readonly'      => true,
        
            'listOrderBy'    => 'id',
            'listOrderByDir' => 'ASC',
        
            'listColumns'    => [
        
                'id'        => [ 'title' => 'UID', 'display' => false ],
                'address'   => 'Address',
                'hostname'  => 'Hostname',
                'customer'  => [
                    'title'      => 'Customer',
                    'type'       => self::$FE_COL_TYPES[ 'HAS_ONE' ],
                    'controller' => 'customer',
                    'action'     => 'view',
                    'idField'    => 'customerid'
                ],
            ]
        ];
            
        // display the same information in the view as the list
        $this->_feParams->viewColumns = array_merge(
            $this->_feParams->listColumns,
            [
                'vlan'  => [
                    'title'      => 'VLAN',
                    'type'       => self::$FE_COL_TYPES[ 'HAS_ONE' ],
                    'controller' => 'vlan',
                    'action'     => 'view',
                    'idField'    => 'vlanid'
                ]
            ]
        );
    }
    
    
    /**
     * Provide array of users for the listAction and viewAction
     *
     * @param int $id The `id` of the row to load for `viewAction`. `null` if `listAction`
     */
    protected function listGetData( $id = null )
    {
        $this->view->vlans = $vlans = $this->getD2EM()->getRepository( '\\Entities\\Vlan' )->getNames();
        
        $qb = $this->getD2EM()->createQueryBuilder()
            ->select( 'ip.id as id, ip.address as address,
                v.name AS vlan,
                vli.ipv4hostname hostname,
                c.name AS customer, c.id AS customerid'
            )
            ->from( '\\Entities\\IPv4Address', 'ip' )
            ->leftJoin( 'ip.Vlan', 'v' )
            ->leftJoin( 'ip.VlanInterface', 'vli' )
            ->leftJoin( 'vli.VirtualInterface', 'vi' )
            ->leftJoin( 'vi.Customer', 'c' );
    
        if( isset( $this->_feParams->listOrderBy ) )
            $qb->orderBy( $this->_feParams->listOrderBy, isset( $this->_feParams->listOrderByDir ) ? $this->_feParams->listOrderByDir : 'ASC' );
    
        if( $id !== null )
            $qb->andWhere( 'ip.id = ?1' )->setParameter( 1, $id );
    
        if( ( $vid = $this->getParam( 'vlan', false ) ) && isset( $vlans[$vid] ) )
        {
            $this->view->vid = $vid;
            $qb->where( 'v.id = ?2' )->setParameter( 2, $vid );
        }
        else if( isset( $this->_options['identity']['vlans']['default'] ) )
            $this->view->vid = $this->_options['identity']['vlans']['default'];
        
        //OSS_Debug::dd( $qb->getQuery()->getResult() );
        return $qb->getQuery()->getResult();
    }
    
    public function addAddressesAction()
    {
        $f = new INEX_Form_AddAddresses( null, false, '' );
        
        $f->setAction( Zend_Controller_Front::getInstance()->getBaseUrl() . '/'
            . $this->getRequest()->getParam( 'controller' ) . "/add-addresses" );
 
        if( $this->inexGetPost( 'commit' ) !== null && $f->isValid( $_POST ) )
        {
            do
            {
                try
                {
                    $addrfam = $f->getValue( 'type' );
                    $conn = Doctrine_Manager::connection();
                    $conn->beginTransaction();
                    
                    for( $i = 0; $i < intval( $_POST['numaddrs'] ); $i++ )
                    {
                        if( $addrfam == 'IPv4' )
                            $ip = new Ipv4address();
                        else if( $addrfam == 'IPv6' )
                            $ip = new Ipv6address();
                        else
                            die( 'Invalid address family!' );

                        $ip['vlanid']   = $f->getValue( 'vlanid' );
                        $ip['address']  = trim( $_POST[ 'np_name' . $i ] );
                        $ip->save();
                    }
                    
                    $conn->commit();
                     
                    $this->getLogger()->notice( intval( $_POST['numaddrs'] ) . ' new ' . $addrfam . ' addresses created' );
                    $this->session->message = new INEX_Message(  intval( $_POST['numaddrs'] ) . ' new ' . $addrfam . ' addresses created', "success" );
                    
                    if( $addrfam == 'IPv4' )
                        $redir = 'ipv4';
                    else
                        $redir = 'ipv6';

                    $this->_redirect( "{$redir}-address/list/vlanid/" . $f->getValue( 'vlanid' ) );
                }
                catch( Exception $e )
                {
                    $conn->rollback();
                    
                    Zend_Registry::set( 'exception', $e );
                    return( $this->_forward( 'error', 'error' ) );
                }
            }while( false );
        }

        $this->view->form   = $f->render( $this->view );

        $this->view->display( 'ipv4-address/add-addresses.tpl' );
    }
}

