<?php $this->layout('services/lg/layout') ?>

<?php $this->section('title') ?>
    <small>Route Search</small>
<?php $this->append() ?>

<?php $this->section('content') ?>
    <div class="card col-sm-12 mb-4">
        <div class="card-header"><b>Prefix Lookup</b></div>
        <div class="card-body">

            <?= Former::open()->method( 'get' )
                ->action( '#' )
                ->customInputWidthClass( 'col-sm-6' )
                ->addClass( 'col-md-10' )
                ->actionButtonsCustomClass( "grey-box");
            ?>

                <?= Former::text( 'net' )
                    ->id( 'net' )
                    ->label( 'IP Address/Prefix' )
                    ->placeholder( '192.0.2.0/24 | 2001:db8:7:2::/64' )
                    ->blockHelp( '' );
                ?>

                <?= Former::radios( 'Internal Use' )
                    ->label( ' ' )
                    ->radios([
                        'Lookup table' => [ 'name' => 'source_selector', 'value' => 'table' ],
                        'Lookup protocol' => [ 'name' => 'source_selector', 'value' => 'protocol'],
                    ])->check( 'table' )
                    ->blockHelp( '' );
                ?>

                <?= Former::select( 'source' )
                    ->id( 'source' )
                    ->label( 'Source' )
                    ->placeholder( 'Choose a source' )
                    ->addClass( 'chzn-select' )
                    ->blockHelp( '' );
                ?>

                <?=Former::actions( Former::primary_submit( 'Search' )->id( 'submit' )->class( "mb-2 mb-sm-0"),
                    Former::success_button( 'Help' )->id( 'help-btn' )->class( "mb-2 mb-sm-0")
                );?>

            <?= Former::close() ?>
        </div>
    </div>

    <div class="card col-sm-12">
        <div class="card-header"><b>Community Search</b></div>
        <div class="card-body">
            <div class="form-group row col-md-10">
                <label class="col-sm-4 col-form-label">Predefined Community</label>
                <div class="col-sm-6">
                    <select id="community-preset" class="form-control">
                        <option value="">-- Select a community --</option>
                        <?php $rsAsn = $t->lg->router()->asn; ?>
                        <optgroup label="RPKI Status">
                            <option value="<?= $rsAsn ?>:1000:1">RPKI Valid</option>
                            <option value="<?= $rsAsn ?>:1000:2">RPKI Unknown</option>
                            <option value="<?= $rsAsn ?>:1000:3">RPKI Not Checked</option>
                        </optgroup>
                        <optgroup label="IRRDB Status">
                            <option value="<?= $rsAsn ?>:1001:1">IRRDB Valid</option>
                            <option value="<?= $rsAsn ?>:1001:0">IRRDB Invalid</option>
                            <option value="<?= $rsAsn ?>:1001:2">IRRDB Not Checked</option>
                            <option value="<?= $rsAsn ?>:1001:3">IRRDB More Specific</option>
                        </optgroup>
                        <optgroup label="Filtering Reasons">
                            <option value="<?= $rsAsn ?>:1101:1">Prefix Length Too Long</option>
                            <option value="<?= $rsAsn ?>:1101:2">Prefix Length Too Short</option>
                            <option value="<?= $rsAsn ?>:1101:3">Bogon</option>
                            <option value="<?= $rsAsn ?>:1101:4">Bogon ASN</option>
                            <option value="<?= $rsAsn ?>:1101:5">AS Path Too Long</option>
                            <option value="<?= $rsAsn ?>:1101:7">First AS Not Peer AS</option>
                            <option value="<?= $rsAsn ?>:1101:8">Next Hop Not Peer IP</option>
                            <option value="<?= $rsAsn ?>:1101:9">IRRDB Prefix Filtered</option>
                            <option value="<?= $rsAsn ?>:1101:10">IRRDB Origin AS Filtered</option>
                            <option value="<?= $rsAsn ?>:1101:11">Prefix Not In Origin AS</option>
                            <option value="<?= $rsAsn ?>:1101:12">RPKI Unknown</option>
                            <option value="<?= $rsAsn ?>:1101:13">RPKI Invalid</option>
                            <option value="<?= $rsAsn ?>:1101:14">Transit Free ASN</option>
                            <option value="<?= $rsAsn ?>:1101:15">Too Many Communities</option>
                        </optgroup>
                        <optgroup label="Well-Known">
                            <option value="65535:65281">NO_EXPORT</option>
                            <option value="65535:65282">NO_ADVERTISE</option>
                            <option value="65535:666">BLACKHOLE</option>
                        </optgroup>
                    </select>
                </div>
            </div>

            <div class="form-group row col-md-10">
                <label class="col-sm-4 col-form-label">Custom Community</label>
                <div class="col-sm-6">
                    <input type="text" id="community-custom" class="form-control"
                        placeholder="e.g. 0:4826 (standard) or <?= $rsAsn ?>:0:4826 (large)">
                    <small class="form-text text-muted">
                        Standard: <code>x:y</code> &nbsp; Large: <code>x:y:z</code>
                    </small>
                </div>
            </div>

            <div class="form-group row col-md-10">
                <div class="col-sm-6 offset-sm-4">
                    <button id="community-search" class="btn btn-primary">
                        <i class="fa fa-search"></i> Search
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="route-modal" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
            </div>
        </div>
    </div>
<?php $this->append() ?>

<?php $this->section('scripts') ?>
    <?= $t->insert('services/lg/js/route-search') ?>
<?php $this->append() ?>
