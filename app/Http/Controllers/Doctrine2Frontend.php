<?php

namespace IXP\Http\Controllers;

/*
 * Copyright (C) 2009-2017 Internet Neutral Exchange Association Company Limited By Guarantee.
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

use D2EM;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

use Illuminate\View\View;

use Illuminate\Support\Facades\View as ViewFacade;

use IXP\Utils\View\Alert\Alert;
use IXP\Utils\View\Alert\Container as AlertContainer;

/**
 * Doctrine2Frontend Functions
 *
 * Based on Barry's original code from:
 *     https://github.com/opensolutions/OSS-Framework/blob/master/src/OSS/Controller/Action/Trait/Doctrine2Frontend.php
 *
 * @author     Barry O'Donovan <barry@islandbridgenetworks.ie>
 * @author     Yann Robin <yann@islandbridgenetworks.ie>
 * @category   Http\Controllers
 * @copyright  Copyright (C) 2009-2017 Internet Neutral Exchange Association Company Limited By Guarantee
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
abstract class Doctrine2Frontend extends Controller {

    /**
     * Parameters used by the frontend controller
     * @var object Parameters used by the frontend controller
     */
    protected $feParams = null;

    protected $data     = null;

    protected $params   = null;

    protected $view     = null;

    /**
     * Column / table data types when displaying data.
     * @var array
     */
    static public $FE_COL_TYPES = [
        'HAS_ONE'  => 'hasOne',
        'DATETIME' => 'datetime',
        'DATE'     => 'date',
        'TIME'     => 'time',
        'SCRIPT'   => 'script',
        'SPRINTF'  => 'sprintf',
        'REPLACE'  => 'replace',
        'XLATE'    => 'xlate',
        'YES_NO'   => 'yes_no'
    ];


    /**
     * The class's initialisation method.
     *
     */
    public function __construct( ){
        $this->feInit();
        $this->data[ 'col_types' ] = self::$FE_COL_TYPES;
    }



    /**
     * This must be overridden.
     */
    protected function feInit(){
        abort( 'FrontEnd controllers require an feInit() function' );
    }

    /**
     * Provide array of users for the list action and view action
     *
     * @param int $id The `id` of the row to load for `view` action. `null` if `list` action.
     * @return array
     */
    abstract protected function listGetData( $id = null );


    /**
     * List the contents of a database table.
     *
     * @return View
     */
    public function list() {
        $this->data[ 'data' ]           = $this->listGetData() ;
        $this->view[ 'listScript' ]     = $this->resolveTemplate( 'js/list' );

        return $this->display( 'list' );
    }

    /**
     * Prepares data for view and AJAX view
     */
    protected function addPrepareData( $id = null ) {}

    /**
     * Add (or edit) an object
     */
    public function add()
    {
        $this->params           = $this->addPrepareData();

        return $this->display( 'edit' );
    }

    /**
     * Provide single object for view. Uses `listGetData()`
     *
     * @param int $id The `id` of the row to load for `view` action.
     * @return array
     */
    protected function viewGetData( $id ) {
        $data = $this->listGetData( $id );

        if( is_array( $data ) && isset( $data[0] ) )
            return $data[0];

        abort( 404);
    }

    /**
     * Add (or edit) an object
     */
    public function view( $id ){
        $this->data[ 'data' ]           = $this->viewGetData( $id ) ;

        return $this->display( 'view' );
    }

    /**
     * Add (or edit) an object
     */
    public function prepareEditAction() {}


    /**
     * Add (or edit) an object
     * @param int $id ID of the object to edit
     * @return view
     */
    public function edit( $id ){
        $this->params = $this->addPrepareData( $id );

        return $this->display( 'edit' );
    }

    /**
     * Delete an object
     *
     * @param int $id ID of the object to delete
     * @return RedirectResponse
     */
    public function delete( $id ) {
        $entity = $this->feParams->entity;

        if( !( $object = D2EM::getRepository( $entity )->find( $id ) ) ) {
            return abort( '404' );
        }

        D2EM::remove( $object );
        D2EM::flush();

        AlertContainer::push(  $this->feParams->titleSingular." deleted." , Alert::SUCCESS );
        return redirect()->action( $this->feParams->defaultController.'@'.$this->feParams->defaultAction );
    }

    /**
     * Edit a physical interface (set all the data needed)
     */
    public function storePrepareAction( Request $request ) {}

    /**
     * Edit a physical interface (set all the data needed)
     */
    public function store( Request $request )
    {
       $this->storePrepareAction( $request );

       AlertContainer::push(  $this->feParams->titleSingular." ".($request->input( 'id')  ? "edited." : "added." ), Alert::SUCCESS );

       return redirect()->action( $this->feParams->defaultController.'@'.$this->feParams->defaultAction );
    }

    /**
     * Displays the standard Frontend template or the controllers overridden version.
     *
     * @see _resolveTemplate()
     * @param string $tpl The template to display
     * @return view
     */
    protected function display( $tpl ){
        return view( $this->resolveTemplate( $tpl ) )->with( [ 'data' => $this->data , 'view' => $this->view, 'params' => $this->params ]);
    }

    /**
     * Resolves the standard Frontend template or the controllers overridden version.
     *
     * All frontend actions have their own template: `frontend/{$action}.foil.php` which is
     * displayed by default. You can however override these by creating a template named:
     * `{$controller}/{$action}.foil.php`. This function looks for an overriding template
     * and displays that if it exists, otherwise it displays the default.
     *
     * This will also work for subdirectories: e.g. `$tpl = forms/add.phtml` is also valid.
     *
     * @param string $tpl The template to display
     * @return string|bool The template to use of false if none found
     */
    protected function resolveTemplate( $tpl ){
        if( ViewFacade::exists ( $this->feParams->viewFolderName . "/{$tpl}" ) ) {
            return $this->feParams->viewFolderName . "/{$tpl}";
        } else if( ViewFacade::exists( "frontend/{$tpl}"  ) ) {
            return "frontend/{$tpl}";
        }

        abort(404, "No template exists in frontend or controller's view directory for ".$tpl);

        return false;
    }

}