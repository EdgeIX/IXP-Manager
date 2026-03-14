@component('mail::message')
# MAC Address Change Notification

On **{{ date('Y-m-d H:i:s') }}**, the user **{{ $event->user->username }}** ({{ $event->user->email }})
of **{{ $event->customer }}** {{ $event->action === 'add' ? 'added' : 'deleted' }}
the MAC address `{{ $event->mac }}`.

@component('mail::panel')

@php
    $vli = $event->vli;
    $vi = $vli->virtualInterface ?? null;
    $pi = $vi ? $vi->physicalInterfaces->first() : null;
    $sp = $pi ? $pi->switchPort : null;
    $sw = $sp ? $sp->switcher : null;
    $cabinet = $sw ? $sw->cabinet : null;
    $location = $cabinet ? $cabinet->location : null;
    $infra = $sw ? \IXP\Models\Infrastructure::find( $sw->infrastructure ) : null;
    $vlan = $vli->vlan ?? null;

    $portName = null;
    if ( $vi && $vi->lag_framing && $vi->bundleName() ) {
        $portName = $vi->bundleName();
    } elseif ( $sp ) {
        $portName = $sp->name;
    }
@endphp

| | |
|---|---|
| **Action** | {{ $event->action === 'add' ? 'Added' : 'Deleted' }} |
| **MAC Address** | `{{ $event->mac }}` |
| **VLAN Interface** | [#{{ $vli->id }}]({{ route( 'layer2-address@forVlanInterface', [ 'vli' => $vli->id ] ) }}) |
@if($vlan)
| **VLAN** | {{ $vlan->name }} ({{ $vlan->number }}) |
@endif
@if($infra)
| **Infrastructure** | {{ $infra->name }} |
@endif
@if($location)
| **Location** | {{ $location->name }} |
@endif
@if($sw)
| **Switch** | {{ $sw->name }} |
@endif
@if($portName)
| **Port** | {{ $portName }} |
@endif

@endcomponent

@component('mail::button', ['url' => route( 'layer2-address@forVlanInterface', [ 'vli' => $vli->id ] )])
View VLAN Interface
@endcomponent

**NB:** You should view the link above before making any switch changes to ensure the {{ config( 'ixp_fe.lang.customer.one' ) }} has completed all their editing.

@endcomponent
