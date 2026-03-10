<script>
    $(document).ready( function() {
        // allow enough space for form labels:
        $( 'label.col-lg-2' ).removeClass('col-lg-2');
        $( '#div-well' ).show();

        const dd_custid        = $( '#custid' );
        const dd_resellerVi    = $( '#reseller_vi_id' );
        const resellerPortArea = $( '#reseller-port-area' );

        /**
         * When customer changes, check if they are a resold customer.
         * If so, fetch the reseller's ports and show the dropdown.
         * If not, hide the dropdown and unlock switch/port.
         */
        dd_custid.on( 'change', function() {
            let custId = $( this ).val();
            resellerPortArea.hide();
            dd_resellerVi.html( '<option value="">-- Dedicated Port (not sub-rate) --</option>' );
            unlockSwitchPort();

            if( !custId ) return;

            let url = "<?= url( '/interfaces/virtual/reseller-ports' ) ?>/" + custId;

            $.ajax( url, { method: "GET" } )
                .done( function( data ) {
                    if( data.length === 0 ) return;

                    // Customer is resold — show the reseller port dropdown
                    let options = '<option value="">-- Dedicated Port (not sub-rate) --</option>';
                    $.each( data, function( key, port ) {
                        options += '<option value="' + port.vi_id + '"'
                            + ' data-switch-id="' + port.switch_id + '"'
                            + ' data-switchport-id="' + port.switchport_id + '"'
                            + '>' + port.label + '</option>';
                    });

                    dd_resellerVi.html( options );
                    resellerPortArea.show();
                });
        });

        /**
         * When reseller port is selected, lock the switch and port dropdowns
         * to match the reseller's port. When "Dedicated Port" is selected,
         * unlock them for normal use.
         */
        dd_resellerVi.on( 'change', function() {
            let selected = $( this ).find( ':selected' );
            let switchId     = selected.data( 'switch-id' );
            let switchportId = selected.data( 'switchport-id' );

            if( !switchId || !switchportId ) {
                unlockSwitchPort();
                return;
            }

            lockSwitchPort( switchId, switchportId );
        });

        /**
         * Lock switch and port dropdowns to specific values.
         * Sets the switch, waits for port list to load, then selects the port.
         */
        function lockSwitchPort( switchId, switchportId ) {
            let dd_switch = $( '#switch' );
            let dd_port   = $( '#switchportid' );

            // Set the switch value and trigger change to load ports
            dd_switch.val( switchId ).trigger( 'change' );

            // Disable the switch dropdown
            dd_switch.prop( 'disabled', true );
            // Add a hidden input so the disabled select value still submits
            if( $( '#switch-hidden' ).length === 0 ) {
                dd_switch.after( '<input type="hidden" id="switch-hidden" name="switch" value="' + switchId + '">' );
            } else {
                $( '#switch-hidden' ).val( switchId );
            }

            // Wait for the port AJAX to complete, then select and lock the port
            let checkPorts = setInterval( function() {
                if( dd_port.find( 'option[value="' + switchportId + '"]' ).length > 0
                    || dd_port.find( 'option' ).length > 1 ) {

                    clearInterval( checkPorts );

                    // The reseller's port is already assigned to a PI, so it won't appear
                    // in the free ports list. Add it manually if missing.
                    if( dd_port.find( 'option[value="' + switchportId + '"]' ).length === 0 ) {
                        let label = dd_resellerVi.find( ':selected' ).text();
                        dd_port.append( '<option value="' + switchportId + '">' + label + ' (Reseller)</option>' );
                    }

                    dd_port.val( switchportId ).trigger( 'change' );
                    dd_port.prop( 'disabled', true );
                    if( $( '#switchportid-hidden' ).length === 0 ) {
                        dd_port.after( '<input type="hidden" id="switchportid-hidden" name="switchportid" value="' + switchportId + '">' );
                    } else {
                        $( '#switchportid-hidden' ).val( switchportId );
                    }
                }
            }, 100 );

            // Safety timeout — stop checking after 5 seconds
            setTimeout( function() { clearInterval( checkPorts ); }, 5000 );
        }

        /**
         * Unlock switch and port dropdowns for normal use.
         */
        function unlockSwitchPort() {
            $( '#switch' ).prop( 'disabled', false );
            $( '#switchportid' ).prop( 'disabled', false );
            $( '#switch-hidden' ).remove();
            $( '#switchportid-hidden' ).remove();
        }
    });
</script>
