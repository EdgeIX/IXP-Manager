<?php
    /**
     * EdgIX skin: Customer overview — Pseudowires tab.
     *
     * Shows pseudowire circuits where this customer is the requester (ordered)
     * or the target (received). Read-only for now; will be extended for
     * customer self-service actions in a future release.
     */

    use EdgeIX\IxpmPseudowire\Models\PwCircuit;

    $c = $t->c; /** @var \IXP\Models\Customer $c */

    $badgeMap = [
        'pending_approval'   => 'warning',
        'approved'           => 'info',
        'provisioning'       => 'info',
        'active'             => 'success',
        'teardown_requested' => 'warning',
        'deprovisioning'     => 'warning',
        'deprovisioned'      => 'secondary',
        'rejected'           => 'danger',
        'cancelled'          => 'secondary',
        'failed'             => 'danger',
    ];

    // Circuits this customer ordered (A-End / requester)
    $ordered = PwCircuit::where( 'requester_customer_id', $c->id )
        ->with( [ 'targetCustomer', 'requesterVirtualInterface.physicalInterfaces.switchPort.switcher', 'targetVirtualInterface.physicalInterfaces.switchPort.switcher' ] )
        ->orderByDesc( 'created_at' )
        ->get();

    // Circuits targeting this customer (Z-End / received)
    $received = PwCircuit::where( 'target_customer_id', $c->id )
        ->where( 'requester_customer_id', '!=', $c->id ) // exclude self-connects from appearing twice
        ->with( [ 'requesterCustomer', 'requesterVirtualInterface.physicalInterfaces.switchPort.switcher', 'targetVirtualInterface.physicalInterfaces.switchPort.switcher' ] )
        ->orderByDesc( 'created_at' )
        ->get();

    // Helper: build a port label from a VirtualInterface
    $portLabel = function( $vi ) {
        if( !$vi ) return '—';
        $pi = $vi->physicalInterfaces->first();
        if( !$pi || !$pi->switchPort || !$pi->switchPort->switcher ) return '—';
        return $pi->switchPort->switcher->name . ' :: ' . $pi->switchPort->name;
    };

    // Terminal states that are no longer "live"
    $terminalStates = [ 'deprovisioned', 'rejected', 'cancelled' ];
?>

<?php if( $ordered->isEmpty() && $received->isEmpty() ): ?>
    <p class="text-muted">No pseudowire circuits found for this account.</p>
<?php else: ?>

    <?php if( $ordered->count() > 0 ): ?>
        <h5 class="mb-3">Ordered Circuits <small class="text-muted">(you are A-End)</small></h5>
        <table id="table-pw-ordered" class="table table-striped tw-shadow-md w-100">
            <thead class="thead-dark">
                <tr>
                    <th>Service ID</th>
                    <th>Status</th>
                    <th>Your Port</th>
                    <th>Remote Party</th>
                    <th>Remote Port</th>
                    <th>Bandwidth</th>
                    <th>Requested</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach( $ordered as $pw ): ?>
                    <tr class="<?= in_array( $pw->state, $terminalStates ) ? 'text-muted' : '' ?>">
                        <td><?= $pw->service_id ?? '#' . $pw->id ?></td>
                        <td>
                            <span class="badge badge-<?= $badgeMap[ $pw->state ] ?? 'secondary' ?>">
                                <?= strtoupper( str_replace( '_', ' ', $pw->state ) ) ?>
                            </span>
                        </td>
                        <td>
                            <?= $portLabel( $pw->requesterVirtualInterface ) ?>
                            <?php if( $pw->requester_subif_vlan ): ?>
                                <br><small class="text-muted">VLAN <?= $pw->requester_subif_vlan ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $t->ee( $pw->targetCustomer->name ?? '—' ) ?>
                            <?php if( $pw->targetCustomer ): ?>
                                <br><small class="text-muted">AS<?= $pw->targetCustomer->autsys ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $portLabel( $pw->targetVirtualInterface ) ?>
                            <?php if( $pw->target_subif_vlan ): ?>
                                <br><small class="text-muted">VLAN <?= $pw->target_subif_vlan ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= $pw->bandwidth_mbps ? $pw->bandwidth_mbps . ' Mbps' : '—' ?></td>
                        <td><?= $pw->requested_at ? $pw->requested_at->format( 'Y-m-d' ) : $pw->created_at->format( 'Y-m-d' ) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if( $received->count() > 0 ): ?>
        <?php if( $ordered->count() > 0 ): ?>
            <hr class="my-4">
        <?php endif; ?>
        <h5 class="mb-3">Received Circuits <small class="text-muted">(you are Z-End)</small></h5>
        <table id="table-pw-received" class="table table-striped tw-shadow-md w-100">
            <thead class="thead-dark">
                <tr>
                    <th>Service ID</th>
                    <th>Status</th>
                    <th>Your Port</th>
                    <th>Remote Party</th>
                    <th>Remote Port</th>
                    <th>Bandwidth</th>
                    <th>Requested</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach( $received as $pw ): ?>
                    <tr class="<?= in_array( $pw->state, $terminalStates ) ? 'text-muted' : '' ?>">
                        <td><?= $pw->service_id ?? '#' . $pw->id ?></td>
                        <td>
                            <span class="badge badge-<?= $badgeMap[ $pw->state ] ?? 'secondary' ?>">
                                <?= strtoupper( str_replace( '_', ' ', $pw->state ) ) ?>
                            </span>
                        </td>
                        <td>
                            <?= $portLabel( $pw->targetVirtualInterface ) ?>
                            <?php if( $pw->target_subif_vlan ): ?>
                                <br><small class="text-muted">VLAN <?= $pw->target_subif_vlan ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $t->ee( $pw->requesterCustomer->name ?? '—' ) ?>
                            <?php if( $pw->requesterCustomer ): ?>
                                <br><small class="text-muted">AS<?= $pw->requesterCustomer->autsys ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $portLabel( $pw->requesterVirtualInterface ) ?>
                            <?php if( $pw->requester_subif_vlan ): ?>
                                <br><small class="text-muted">VLAN <?= $pw->requester_subif_vlan ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= $pw->bandwidth_mbps ? $pw->bandwidth_mbps . ' Mbps' : '—' ?></td>
                        <td><?= $pw->requested_at ? $pw->requested_at->format( 'Y-m-d' ) : $pw->created_at->format( 'Y-m-d' ) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php endif; ?>
