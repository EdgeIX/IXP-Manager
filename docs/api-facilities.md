# Facilities API

API for retrieving facility/location data. Requires API key authentication (superuser). Designed for website integration to dynamically display on-net facilities.

## Endpoints

### List Facilities

```
GET /api/v4/facilities
```

Returns all active facilities (those with switches installed).

**Query Parameters:**

| Parameter | Default | Description |
|-----------|---------|------------|
| `active`  | `1`     | `1` = only facilities with active switches, `0` = all facilities including planned/empty |

**Response:**

```json
{
    "facilities": [
        {
            "id": 1,
            "name": "Equinix SY1",
            "shortname": "eqnx-sy1",
            "tag": "SY1",
            "city": "Sydney",
            "country": "AU",
            "address": "47 Bourke Rd, Alexandria NSW 2015",
            "pdb_facility_id": 123,
            "active": true
        },
        {
            "id": 2,
            "name": "NextDC P1",
            "shortname": "nxdc-p1",
            "tag": "P1",
            "city": "Perth",
            "country": "AU",
            "address": "4 Millrose Dr, Malaga WA 6090",
            "pdb_facility_id": 456,
            "active": true
        }
    ],
    "count": 2
}
```

### Get Single Facility

```
GET /api/v4/facilities/{id}
```

Returns detailed information for a single facility, including switches.

**Response:**

```json
{
    "id": 1,
    "name": "Equinix SY1",
    "shortname": "eqnx-sy1",
    "tag": "SY1",
    "city": "Sydney",
    "country": "AU",
    "address": "47 Bourke Rd, Alexandria NSW 2015",
    "pdb_facility_id": 123,
    "active": true,
    "nocemail": "noc@example.net",
    "nocphone": "+61299999999",
    "officephone": "+61299999999",
    "officeemail": "office@example.net",
    "notes": "Main Sydney facility",
    "switches": [
        {
            "name": "pe1syd1",
            "infrastructure": "EdgeIX Sydney",
            "active": true
        }
    ]
}
```

## Usage Examples

### Fetch all on-net facilities

```bash
curl -s -H "X-IXP-Manager-API-Key: YOUR_API_KEY" https://ixp.edgeix.net.au/api/v4/facilities | jq
```

### Fetch all facilities including planned

```bash
curl -s -H "X-IXP-Manager-API-Key: YOUR_API_KEY" "https://ixp.edgeix.net.au/api/v4/facilities?active=0" | jq
```

### Fetch single facility

```bash
curl -s -H "X-IXP-Manager-API-Key: YOUR_API_KEY" https://ixp.edgeix.net.au/api/v4/facilities/1 | jq
```

### JavaScript (website backend integration)

```javascript
// Call from your website's backend (server-side) to keep the API key private
fetch('https://ixp.edgeix.net.au/api/v4/facilities', {
    headers: { 'X-IXP-Manager-API-Key': process.env.IXP_API_KEY }
})
    .then(r => r.json())
    .then(data => {
        data.facilities.forEach(f => {
            console.log(`${f.name} — ${f.city}, ${f.country}`);
        });
    });
```

## CORS

If calling from a different domain (e.g. `www.edgeix.net`), ensure CORS headers are configured on the IXP-Manager server to allow requests from your website domain.

Add to `.env`:
```dotenv
CORS_ALLOWED_ORIGINS=https://www.edgeix.net,https://edgeix.net
```

Or configure in the web server (nginx/Apache) to add `Access-Control-Allow-Origin` headers for the API routes.

## PeeringDB Link

Each facility includes a `pdb_facility_id` field. If set, you can link to the PeeringDB facility page:

```
https://www.peeringdb.com/fac/{pdb_facility_id}
```
