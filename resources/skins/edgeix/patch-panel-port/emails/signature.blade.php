
@if( trim( $ppp->getPatchPanel()->getLocationDescription() ) != '' || trim( $ppp->getPatchPanel()->getLocationNotes() ) != '' )
#### Notes for the Colocation Provider

{{ env( 'IDENTITY_ORGNAME' ) }}'s records include the following notes to help identify the above patch panel:

@if( trim( $ppp->getPatchPanel()->getLocationDescription() ) != '' )
{{ $ppp->getPatchPanel()->getLocationDescription() }}
@endif

@if( trim( $ppp->getPatchPanel()->getLocationNotes() ) != '' )
{{$ppp->getPatchPanel()->getLocationNotes()}}
@endif

@endif

If you have any queries about this, please reply to this email and our team will be able to assist.

Kind regards,

EdgeIX - https://www.edgeix.net



