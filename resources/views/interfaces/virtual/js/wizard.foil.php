<script>
    $(document).ready( function() {
        // allow enough space for form labels:
        $( 'label.col-lg-2' ).removeClass('col-lg-2');
        $( '#div-well' ).show();

        const dd_custid        = $( '#custid' );
        const dd_resellerVi    = $( '#reseller_vi_id' );
        const resellerPortArea = $( '#reseller-port-area' );

        /**
         * "Skip peering configuration" checkbox — hides VLAN, IPv4/IPv6,
         * and General VLAN Settings when checked. Auto-enables 802.1q trunk.
         */
        $( '#skip_peering' ).on( 'change', function() {
            if( $( this ).is( ':checked' ) ) {
                $( '#peering-vlan-fields' ).hide();
                $( '#peering-ip-fields' ).hide();
                $( '#peering-vlan-settings' ).hide();
                $( '#ipv6-area' ).hide();
                $( '#ipv4-area' ).hide();
                // Auto-enable 802.1q framing (required for dot1q sub-interfaces)
                $( '#trunk' ).prop( 'checked', true ).prop( 'disabled', true );
                if( $( '#trunk-hidden' ).length === 0 ) {
                    $( '#trunk' ).after( '<input type="hidden" id="trunk-hidden" name="trunk" value="1">' );
                }
                // Uncheck IPv4/IPv6 so they don't submit
                $( '#ipv4enabled' ).prop( 'checked', false );
                $( '#ipv6enabled' ).prop( 'checked', false );
            } else {
                $( '#peering-vlan-fields' ).show();
                $( '#peering-ip-fields' ).show();
                $( '#peering-vlan-settings' ).show();
                $( '#trunk' ).prop( 'disabled', false );
                $( '#trunk-hidden' ).remove();
            }
        });

        /**
         * When customer changes, check if they are a resold customer.
         * If so, fetch the reseller's ports and show the dropdown.
         * If not, hide the dropdown and unlock switch/port.
         */
        dd_custid.on( 'change', function() {
            let custId = $( this ).val();
            resellerPortArea.hide();
            dd_resellerVi.html( '<option value="">-- Dedicated Port (not sub-rate) --</option>' );
            // Restore all PI fields in case they were hidden by sub-rate selection
            $( '#switch' ).closest( '.form-group' ).show();
            $( '#switchportid' ).closest( '.form-group' ).show();
            $( '#status' ).closest( '.form-group' ).show();
            $( '#fanout-box' ).show();
            $( '#trunk' ).prop( 'disabled', false );
            $( '#trunk-hidden' ).remove();
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
                // Dedicated port — show all PI fields, unlock everything, unforce trunk
                $( '#switch' ).closest( '.form-group' ).show();
                $( '#switchportid' ).closest( '.form-group' ).show();
                $( '#status' ).closest( '.form-group' ).show();
                $( '#fanout-box' ).show();
                unlockSwitchPort();
                $( '#trunk' ).prop( 'disabled', false );
                $( '#trunk-hidden' ).remove();
                return;
            }

            // Sub-rate on reseller port — hide switch/port/status, keep speed/duplex visible, force 802.1q
            $( '#switch' ).closest( '.form-group' ).hide();
            $( '#switchportid' ).closest( '.form-group' ).hide();
            $( '#status' ).closest( '.form-group' ).hide();
            $( '#fanout-box' ).hide();
            $( '#trunk' ).prop( 'checked', true ).prop( 'disabled', true );
            if( $( '#trunk-hidden' ).length === 0 ) {
                $( '#trunk' ).after( '<input type="hidden" id="trunk-hidden" name="trunk" value="1">' );
            }
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

        // On page load, if a customer is already selected (pre-selected via URL),
        // trigger the reseller port check immediately.
        let initialCustId = dd_custid.val() || $( 'input[name="custid"]' ).val();
        if( initialCustId ) {
            let url = "<?= url( '/interfaces/virtual/reseller-ports' ) ?>/" + initialCustId;

            $.ajax( url, { method: "GET" } )
                .done( function( data ) {
                    if( data.length === 0 ) return;

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
        }
    });
</script>
