<?php
    /** @var array $bundles - array of CoreBundle models passed from parent template */
?>
<table class="table table-striped table-sm mb-0 cb-table">
    <thead class="thead-dark">
        <tr>
            <th>Description</th>
            <th>Type</th>
            <th>Enabled</th>
            <th>Switch A</th>
            <th>Switch B</th>
            <th>Capacity</th>
            <th>ISIS Metric</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach( $bundles as $cb ):
            /** @var \IXP\Models\CoreBundle $cb */
            $clsNb   = $cb->coreLinks->count();
            $piSpeed = $cb->speedPi();
            ?>
            <tr>
                <td>
                    <?= $t->ee( $cb->description ) ?>
                </td>
                <td>
                    <?= $t->ee( $cb->typeText() ) ?>
                </td>
                <td>
                    <?php if( !$cb->enabled ): ?>
                        <i class="fa fa-remove text-danger"></i>
                    <?php elseif( $cb->enabled && $cb->allCoreLinksEnabled() ): ?>
                        <i class="fa fa-check text-success"></i>
                    <?php else: ?>
                        <span class="badge badge-warning">
                            <?= $cb->coreLinks()->active()->count() ?> / <?= $clsNb ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= $t->ee( $cb->switchSideX( true )->name ?? '' ) ?>
                </td>
                <td>
                    <?= $t->ee( $cb->switchSideX( false )->name ?? '' ) ?>
                </td>
                <td data-sort="<?= $clsNb * $piSpeed ?>">
                    <?= $t->scaleBits( $clsNb * $piSpeed * 1000000, 0 ) ?>
                </td>
                <td>
                    <?= $cb->cost ?? '<span class="text-muted">-</span>' ?>
                </td>
                <td>
                    <div class="btn-group btn-group-sm" role="group">
                        <a class="btn btn-white" href="<?= route( 'core-bundle@edit', [ 'cb' => $cb->id ] ) ?>" title="Edit">
                            <i class="fa fa-pencil"></i>
                        </a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
