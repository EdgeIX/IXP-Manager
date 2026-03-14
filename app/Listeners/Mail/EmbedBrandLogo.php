<?php

namespace IXP\Listeners\Mail;

use Illuminate\Mail\Events\MessageSending;

/**
 * Embed the brand logo as an inline CID attachment in every outgoing email
 * so that email clients display it without requiring "Load Remote Images".
 */
class EmbedBrandLogo
{
    public function handle( MessageSending $event ): void
    {
        $logoPath = public_path( 'images/EdgeIX-Mark-Brand.png' );

        if( !file_exists( $logoPath ) ) {
            return;
        }

        $event->message->embedFromPath( $logoPath, 'brand-logo', 'image/png' );
    }
}
