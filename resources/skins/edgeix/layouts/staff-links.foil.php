<?php // EdgeIX-specific admin links (superuser only — included from menus/superuser.foil.php) ?>

<li class="nav-item dropdown <?= !request()->is( 'mac-sync*' ) ?: 'active' ?>">
    <a class="nav-link dropdown-toggle center-dd-caret d-flex" href="#" id="navbarDropdownStaffLink" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        EdgeIX
    </a>
    <div class="dropdown-menu" aria-labelledby="navbarDropdownStaffLink">
        <a class="dropdown-item <?= !request()->is( 'mac-sync*' ) ?: 'active' ?>" href="<?= route( 'mac-sync@index' ) ?>">
            MAC Sync
        </a>
    </div>
</li>