<script>
    $( document ).ready( function() {
        $( '.cb-table' ).dataTable( {
            responsive: true,
            paging: false,
            searching: false,
            info: false,
            order: [[ 0, 'asc' ]],
            columnDefs: [
                { targets: 0, responsivePriority: 1 },
                { targets: -1, responsivePriority: 2 },
                { targets: 2, type: "string" },
            ],
        });
    });
</script>
