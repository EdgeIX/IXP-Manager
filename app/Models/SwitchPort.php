<?php

namespace IXP\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Log;
use OSS_SNMP\MIBS\Extreme\Port;
use OSS_SNMP\MIBS\Iface;
use OSS_SNMP\SNMP;
use OSS_SNMP\MIBS\MAU as MauMib;
use stdClass;

/**
 * IXP\Models\SwitchPort
 *
 * @property int $id
 * @property int|null $switchid
 * @property int|null $type
 * @property string|null $name
 * @property int $active
 * @property int|null $ifIndex
 * @property string|null $ifName
 * @property string|null $ifAlias
 * @property int|null $ifHighSpeed
 * @property int|null $ifMtu
 * @property string|null $ifPhysAddress
 * @property int|null $ifAdminStatus
 * @property int|null $ifOperStatus
 * @property int|null $ifLastChange
 * @property string|null $lastSnmpPoll
 * @property int|null $lagIfIndex
 * @property string|null $mauType
 * @property string|null $mauState
 * @property string|null $mauAvailability
 * @property string|null $mauJacktype
 * @property int|null $mauAutoNegSupported
 * @property int|null $mauAutoNegAdminState
 * @property-read \IXP\Models\PatchPanelPort|null $patchPanelPort
 * @property-read \IXP\Models\PhysicalInterface|null $physicalInterface
 * @property-read \IXP\Models\Switcher|null $switcher
 * @method static Builder|SwitchPort newModelQuery()
 * @method static Builder|SwitchPort newQuery()
 * @method static Builder|SwitchPort query()
 * @method static Builder|SwitchPort whereActive($value)
 * @method static Builder|SwitchPort whereId($value)
 * @method static Builder|SwitchPort whereIfAdminStatus($value)
 * @method static Builder|SwitchPort whereIfAlias($value)
 * @method static Builder|SwitchPort whereIfHighSpeed($value)
 * @method static Builder|SwitchPort whereIfIndex($value)
 * @method static Builder|SwitchPort whereIfLastChange($value)
 * @method static Builder|SwitchPort whereIfMtu($value)
 * @method static Builder|SwitchPort whereIfName($value)
 * @method static Builder|SwitchPort whereIfOperStatus($value)
 * @method static Builder|SwitchPort whereIfPhysAddress($value)
 * @method static Builder|SwitchPort whereLagIfIndex($value)
 * @method static Builder|SwitchPort whereLastSnmpPoll($value)
 * @method static Builder|SwitchPort whereMauAutoNegAdminState($value)
 * @method static Builder|SwitchPort whereMauAutoNegSupported($value)
 * @method static Builder|SwitchPort whereMauAvailability($value)
 * @method static Builder|SwitchPort whereMauJacktype($value)
 * @method static Builder|SwitchPort whereMauState($value)
 * @method static Builder|SwitchPort whereMauType($value)
 * @method static Builder|SwitchPort whereName($value)
 * @method static Builder|SwitchPort whereSwitchid($value)
 * @method static Builder|SwitchPort whereType($value)
 * @mixin \Eloquent
 */
class SwitchPort extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'switchport';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'switchid',
        'type',
        'name',
        'ifName',
        'ifAlias',
        'ifHighSpeed',
        'ifMtu',
        'ifPhysAddress',
        'ifAdminStatus',
        'ifOperStatus',
        'ifLastChange',
        'lastSnmpPoll',
        'ifIndex',
        'active',
        'lagIfIndex',
        'mauType',
        'mauState',
        'mauAvailability',
        'mauJacktype',
        'mauAutoNegSupported',
        'mauAutoNegAdminState',
    ];

    const TYPE_UNSET          = 0;
    const TYPE_PEERING        = 1;
    const TYPE_MONITOR        = 2;
    const TYPE_CORE           = 3;
    const TYPE_OTHER          = 4;
    const TYPE_MANAGEMENT     = 5;

    /**
     * For resellers, we need to enforce the one port - one mac - one address rule
     * on the peering LAN. Depending on switch technology, this will be done using
     * a virtual ethernet port or a dedcaited fanout switch. A fanout port is a port
     * that sends a resold member's traffic to a peering port / switch.
     *
     * I.e. it is an output port to the exchange to connects to a standard peering
     * input port.
     *
     * @var int
     */
    const TYPE_FANOUT         = 6;

    /**
     * For resellers, we need an uplink port(s) through which they deliver reseller
     * connections.
     *
     * @var int
     */
    const TYPE_RESELLER       = 7;

    public static $TYPES = array(
        self::TYPE_UNSET      => 'Unset / Unknown',
        self::TYPE_PEERING    => 'Peering',
        self::TYPE_MONITOR    => 'Monitor',
        self::TYPE_CORE       => 'Core',
        self::TYPE_OTHER      => 'Other',
        self::TYPE_MANAGEMENT => 'Management',
        self::TYPE_FANOUT     => 'Fanout',
        self::TYPE_RESELLER   => 'Reseller'
    );

    // This array is for matching data from OSS_SNMP to the switchport database table.
    // See snmpUpdate() below
    public static $SNMP_MAP = [
        'descriptions'    => 'Name',
        'names'           => 'IfName',
        'aliases'         => 'IfAlias',
        'highSpeeds'      => 'IfHighspeed',
        'mtus'            => 'IfMtu',
        'physAddresses'   => 'IfPhysAddress',
        'adminStates'     => 'IfAdminStatus',
        'operationStates' => 'IfOperStatus',
        'lastChanges'     => 'IfLastChange'
    ];

    /**
     * Mappings for OSS_SNMP fucntions to SwitchPort members
     */
    public static $OSS_SNMP_MAU_MAP = [
        'types'             => [ 'fn' => 'MauType',         'xlate' => false ],
        'states'            => [ 'fn' => 'MauState',        'xlate' => true ],
        'mediaAvailable'    => [ 'fn' => 'MauAvailability', 'xlate' => true ],
        'jackTypes'         => [ 'fn' => 'MauJacktype',     'xlate' => true ],
        'autonegSupported'  => [ 'fn' => 'MauAutoNegSupported'  ],
        'autonegAdminState' => [ 'fn' => 'MauAutoNegAdminState' ]
    ];



    /**
     * Get the switcher that own the switch port
     */
    public function switcher(): BelongsTo
    {
        return $this->belongsTo(Switcher::class, 'switchid' );
    }

    /**
     * Get the patch panel ports for this switch port
     */
    public function physicalInterface(): HasOne
    {
        return $this->hasOne(PhysicalInterface::class, 'switchportid' );
    }

    /**
     * Get the patch panel ports for this switch port
     */
    public function patchPanelPort(): HasOne
    {
        return $this->hasOne(PatchPanelPort::class, 'switch_port_id' );
    }

    /**
     * Turn the database integer representation of the type into text as
     * defined in the self::$TYPES array (or 'Unknown')
     *
     * @return string
     */
    public function resolveType(): string
    {
        return self::$TYPES[ $this->type ];
    }

    /**
     * Is this an unset port?
     * @return boolean
     */
    public function isTypeUnset():bool
    {
        return $this->type === self::TYPE_UNSET;
    }

    /**
     * Is this a peering port?
     * @return boolean
     */
    public function isTypePeering(): bool
    {
        return $this->type === self::TYPE_PEERING;
    }

    /**
     * Is this a reseller port?
     * @return boolean
     */
    public function isTypeReseller(): bool
    {
        return $this->type === self::TYPE_RESELLER;
    }

    /**
     * Is this a core port?
     * @return boolean
     */
    public function isTypeCore(): bool
    {
        return $this->type === self::TYPE_CORE;
    }

    /**
     * Is this a fanout port?
     *
     * @return boolean
     */
    public function isTypeFanout(): bool
    {
        return $this->getType() === self::TYPE_FANOUT;
    }

    /**
     * Get the appropriate OID for in octets
     *
     * @return string
     */
    public function oidInOctets(): string
    {
        return Iface::OID_IF_HC_IN_OCTETS;
    }

    /**
     * Get the appropriate OID for out octets
     *
     * @return string
     */
    public function oidOutOctets(): string
    {
        return Iface::OID_IF_HC_OUT_OCTETS;
    }

    /**
     * Get the appropriate OID for in unicast packets
     *
     * @return string
     */
    public function oidInUnicastPackets(): string
    {
        return Iface::OID_IF_HC_IN_UNICAST_PACKETS;
    }

    /**
     * Get the appropriate OID for out unicast packets
     * @return string
     */
    public function oidOutUnicastPackets(): string
    {
        return Iface::OID_IF_HC_OUT_UNICAST_PACKETS;
    }

    /**
     * Get the appropriate OID for in errors
     * @return string
     */
    public function oidInErrors(): string
    {
        return Iface::OID_IF_IN_ERRORS;
    }

    /**
     * Get the appropriate OID for out errors
     * @return string
     */
    public function oidOutErrors(): string
    {
        return Iface::OID_IF_OUT_ERRORS;
    }

    /**
     * Get the appropriate OID for in discards
     * @return string
     */
    public function oidInDiscards(): string
    {
        return Iface::OID_IF_IN_DISCARDS;
    }

    /**
     * Get the appropriate OID for out discards
     *
     * @return string
     */
    public function oidOutDiscards(): string
    {
        switch( $this->switcher->os ) {
            case 'ExtremeXOS':
                return Port::OID_PORT_CONG_DROP_PKTS;
                break;
            default:
                return Iface::OID_IF_OUT_DISCARDS;
                break;
        }
    }

    /**
     * Get the appropriate OID for in broadcasts
     *
     * @return string
     */
    public function oidInBroadcasts(): string
    {
        return Iface::OID_IF_HC_IN_BROADCAST;
    }

    /**
     * Get the appropriate OID for out broadcasts
     *
     * @return string
     */
    public function oidOutBroadcasts(): string
    {
        return Iface::OID_IF_HC_OUT_BROADCAST;
    }

    public function ifnameToSNMPIdentifier()
    {
        # escape special characters in ifName as per
        # http://oss.oetiker.ch/mrtg/doc/mrtg-reference.en.html - "Interface by Name" section

        $ifname = preg_replace( '/:/', '\\:', $this->ifName );
        $ifname = preg_replace( '/&/', '\\&', $ifname );
        $ifname = preg_replace( '/@/', '\\@', $ifname );
        $ifname = preg_replace( '/\ /', '\\\ ', $ifname );

        return $ifname;
    }

    /**
     * Gets a listing of switche ports or a single one if an ID is provided
     *
     * @param stdClass $feParams
     * @param int|null $id
     * @param null $params
     *
     * @return array
     */
    public static function getFeList( stdClass $feParams, int $id = null, $params = null ): array
    {
        return self::select( [
            'sp.*',
            's.id AS switchid', 's.name AS switchname'
        ] )
            ->from( 'switchport AS sp' )
            ->leftJoin( 'switch AS s', 's.id', 'sp.switchid')
            ->when( $id , function( Builder $q, $id ) {
                return $q->selectRaw( 'c.id AS cid, c.name AS cname' )
                    ->leftJoin( 'physicalinterface AS pi', 'pi.switchportid', 'sp.id' )
                    ->leftJoin( 'virtualinterface AS vi', 'vi.id', 'pi.virtualinterfaceid' )
                    ->leftJoin( 'cust AS c', 'c.id', 'vi.custid' )
                ->where('sp.id', $id );
            } )->when( isset( $params[ 'params' ][ 'switch' ] ) && $params[ 'params' ][ 'switch' ] , function( Builder $q ) use ( $params ) {
                return $q->where('s.id', $params[ 'params' ][ 'switch' ]->id );
            } )->when( isset( $feParams->listOrderBy ) , function( Builder $q ) use ( $feParams )  {
                return $q->orderBy( $feParams->listOrderBy, $feParams->listOrderByDir ?? 'ASC');
            })->get()->toArray();
    }

    /**
     * Get all the unused optics
     *
     * @param stdClass $feParams
     *
     * @return array
     */
    public static function getUnusedOpticsForFeList( stdClass $feParams ): array
    {
        return self::select( [
            'sp.ifIndex AS ifIndex', 'sp.ifName AS ifName', 'sp.type AS type', 'sp.mauType AS mauType', 'sp.mauState AS mauState', 'sp.mauJacktype AS mauJacktype',
            's.id AS switchid', 's.name AS switchname'
        ] )
            ->from( 'switchport AS sp' )
            ->leftJoin( 'switch AS s', 's.id', 'sp.switchid')
            ->where( 's.mauSupported', 1 )
            ->where( 'sp.ifOperStatus', '!=', 1 )
            ->where( 'sp.mauType', '!=', '(empty)' )
            ->where( 'sp.type', '!=', self::TYPE_MANAGEMENT )
            ->when( $feParams->listOrderBy , function( Builder $q, $orderby ) use ( $feParams )  {
                return $q->orderBy( $orderby, $feParams->listOrderByDir ?? 'ASC');
            })->get()->toArray();
    }

    /**
     * Get all MAU ports
     *
     * @param stdClass $feParams
     * @param int|null $id
     *
     * @return array
     */
    public static function getListMau( stdClass $feParams,  int $id = null ): array
    {
        return self::select( [
            'sp.*',
            's.id AS switchid'
        ] )
            ->from( 'switchport AS sp' )
            ->leftJoin( 'switch AS s', 's.id', 'sp.switchid')
            ->when( $id , function( Builder $q, $id ) {
                return $q->where('s.id', $id );
            } )
            ->when( $feParams->listOrderBy , function( Builder $q, $orderby ) use ( $feParams )  {
                return $q->orderBy( $orderby, $feParams->listOrderByDir ?? 'ASC');
            })->get()->toArray();
    }

    /**
     * Returns all switch ports assigned to a physical interface for a switch.
     *
     * @param $switchid $id Switch ID - switch to query
     *
     * @return array
     */
    public static function getAllPortsAssignedToPIForSwitch( int $switchid ): array
    {
        return self::select( [
            'sp.id AS id', 'sp.name AS name', 'sp.type AS porttype',
            'pi.speed AS speed', 'pi.duplex AS duplex',
            'c.name AS custname'
        ] )
            ->from( 'switchport AS sp' )
            ->join( 'physicalinterface AS pi', 'pi.switchportid', 'sp.id' )
            ->join( 'virtualinterface AS vi', 'vi.id', 'pi.virtualinterfaceid' )
            ->join( 'cust AS c', 'c.id', 'vi.custid' )
            ->where( 'sp.switchid', $switchid )
            ->orderBy( 'id', 'ASC' )
            ->get()->keyBy( 'id' )->toArray();
    }

    /**
     * Returns all available switch ports for a switch.
     *
     * Restrict to only some types of switch port
     * Exclude switch port ids from the list
     *
     * Suitable for other generic use.
     *
     * @param int      $switchid        Switch ID - switch to query
     * @param array    $types           Switch port type restrict to some types only
     * @param array    $excludedSpid    Switch port IDs, if set, those ports are excluded from the results

     * @return array
     */
    public static function getAllPortsForSwitch( int $switchid, $types = [], $excludedSpid = [], bool $notAssignToPI = true ): array
    {
        return self::select( [
            'sp.id AS id', 'sp.name AS name', 'sp.type AS porttype'
        ] )
            ->from( 'switchport AS sp' )
            ->when( $notAssignToPI , function( Builder $q ) {
                return $q->leftJoin( 'physicalinterface AS pi', 'pi.switchportid', 'sp.id' )
                    ->where( 'pi.id', NULL);
            })
            ->when( count( $types ) > 0 , function( Builder $q ) use( $types ) {
                return $q->whereIn( 'sp.type', $types );
            })
            ->when( count( $excludedSpid ) > 0 , function( Builder $q ) use( $excludedSpid ) {
                return $q->whereNotIn( 'sp.id', $excludedSpid );
            })
            ->where( 'sp.switchid', $switchid )
            ->orderBy( 'id', 'ASC' )
            ->get()->keyBy( 'id' )->toArray();
    }

    /**
     * Get all the optic for a dedicated MAU TYPE
     *
     * @param stdClass $feParams
     * @param string|null $mautype
     *
     * @return array Array
     */
    public static function getListMauForType( stdClass $feParams, string $mautype = null  ): array
    {
        return self::select( [
            'sp.*',
            'c.name AS custname', 'c.id AS custid',
            's.id AS switchid', 's.name AS switch'
        ] )
            ->from( 'switchport AS sp' )
            ->leftjoin( 'physicalinterface AS pi', 'pi.switchportid', 'sp.id' )
            ->leftjoin( 'virtualinterface AS vi', 'vi.id', 'pi.virtualinterfaceid' )
            ->leftjoin( 'cust AS c', 'c.id', 'vi.custid' )
            ->leftJoin( 'switch AS s', 's.id', 'sp.switchid')
            ->when( $mautype , function( Builder $q, $mautype ) {
                return $q->where( 'sp.mauType', $mautype);
            }, function ($query) {
                return $query->where( 'sp.mauType', '!=', NULL);
            })
            ->when( $feParams->listOrderBy , function( Builder $q, $orderby ) use ( $feParams )  {
                return $q->orderBy( $orderby, $feParams->listOrderByDir ?? 'ASC');
            })->get()->toArray();
    }

    /**
     * Get all the optic inventory
     *
     * @param stdClass $feParams
     *
     * @return array Array
     */
    public static function getOpticInventory( stdClass $feParams  ): array
    {
        return self::selectRaw(
            'switchport.mauType AS mauType,
            COUNT( switchport.mauType ) AS cnt'
        )
            ->when( $feParams->listOrderBy , function( Builder $q, $orderby ) use ( $feParams )  {
                return $q->orderBy( $orderby, $feParams->listOrderByDir ?? 'ASC');
            })
            ->groupBy( 'switchport.mauType' )
            ->having( 'cnt', '>', '0' )
            ->get()->toArray();
    }

    /**
     * Update switch port details from a SNMP poll of the device.
     *
     * Pass an instance of OSS_Logger if you want logging enabled.
     *
     * @link https://github.com/opensolutions/OSS_SNMP
     *
     *
     * @param SNMP $host An instance of the SNMP host object
     * @param bool $logger An instance of the logger or false
     *
     * @return SwitchPort For fluent interfaces
     *
     * @throws
     */
    public function snmpUpdate( $host, $logger = false ): SwitchPort
    {
        foreach( self::$SNMP_MAP as $snmp => $attribute ) {
            $fn = $attribute;

            switch( $snmp ) {
                case 'lastChanges':
                    $n = $host->useIface()->$snmp( true )[ $this->ifIndex ];

                    // need to allow for small changes due to rounding errors
                    if( $logger !== false && $this->$fn !== $n && abs( $this->$fn - $n ) > 60 )
                        Log::info( "[{$this->switcher->name}]:{$this->name} [Index: {$this->ifIndex}] Updating {$attribute} from [{$this->$fn}] to [{$n}]" );
                    break;
                default:
                    $n = $host->useIface()->$snmp()[ $this->ifIndex ];

                    if( $logger !== false && $this->$fn !== $n )
                        Log::info( "[{$this->switcher->name}]:{$this->name} [Index: {$this->ifIndex}] Updating {$attribute} from [{$this->$fn}] to [{$n}]" );
                    break;
            }

            $this->$fn = $n;
        }

        if( $this->switcher->mauSupported ) {
            foreach( self::$OSS_SNMP_MAU_MAP as $snmp => $attribute ) {
                $fn = $attribute['fn'];

                try {
                    if( isset( $attribute['xlate'] ) ) {
                        $n = $host->useMAU()->$snmp( $attribute['xlate'] );
                        $n = isset( $n[ $this->ifIndex ] ) ? $n[ $this->ifIndex ] : null;
                    } else {
                        $n = $host->useMAU()->$snmp();
                        $n = isset( $n[ $this->ifIndex ] ) ? $n[ $this->ifIndex ] : null;
                    }
                } catch( Exception $e ) {
                    // looks like the switch supports MAU but not all of the MIBs
                    if( $logger !== false ) {
                        Log::debug( "[{$this->switcher->name}]:{$this->name} [Index: {$this->ifIndex}] MAU MIB for {$fn} not supported" );
                    }
                    $n = null;
                }

                if( $snmp === 'types' ) {
                    if( isset( MauMib::$TYPES[ $n ] ) ) {
                        $n = MauMib::$TYPES[ $n ];
                    } else if( $n === null || $n === '.0.0' ) {
                        $n = '(empty)';
                    } else {
                        $n = '(unknown type - oid: ' . $n . ')';
                    }
                }

                if( $this->$fn != $n ) {
                    Log::info( "[{$this->switcher->name}]:{$this->name} [Index: {$this->ifIndex}] Updating {$attribute['fn']} from [{$this->$fn}] to [{$n}]" );
                }

                $this->$fn = $n;
            }
        }

        try {
            // not all switches support this
            // FIXME is there a vendor agnostic way of doing this?

            // are we a LAG port?
            $isAggregatePorts = $host->useLAG()->isAggregatePorts();

            if( isset( $isAggregatePorts[ $this->ifIndex ] ) && $isAggregatePorts[ $this->ifIndex ] )
                $this->lagIfIndex = $host->useLAG()->portAttachedIds()[ $this->ifIndex ];
            else
                $this->lagIfIndex = null;

        } catch( Exception $e ){}

        $this->lastSnmpPoll = now();

        return $this;
    }
}
