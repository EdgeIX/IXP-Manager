<?php
    /** @var Foil\Template\Template $t */
    $this->layout( 'layouts/ixpv4' );
?>

<?php $this->section( 'page-header-preamble' ) ?>
    Route Severs
<?php $this->append() ?>


<?php $this->section('content') ?>

<h3>EdgeIX Route Server Details</h3>


<p>
EdgeIX Operates upto 2 route servers per state based Internet Exchange. Below are the details for peering with these route servers and supported communities.

</p>

<table border="0">
<tr>
    <td width="50"></td>
    <td align="right"><strong>EdgeIX ASN:</strong></td>
    <td width="20"></td>
    <td align="left">24224</td>
</tr>
</table>
<br>
<table style="width:100%" border="0">
<tr>
  <th>City</th>
  <th>RS1 IPv4</th>
  <th>RS1 IPv6</th>
  <th>RS2 IPv4</th>
  <th>RS2 IPv6</th>
</tr>
<tr>
  <td>Adelaide</td>
  <td>103.136.101.1</td>
  <td>2001:df0:680:2::1</td>
  <td>103.136.101.126</td>
  <td>2001:df0:680:2::2</td>
</tr>
<tr>
  <td>Brisbane</td>
  <td>103.136.103.1</td>
  <td>2001:df0:680:4::1</td>
  <td>103.136.103.2</td>
  <td>2001:df0:680:4::2</td>
</tr>
<tr>
  <td>Darwin</td>
  <td>103.136.100.1</td>
  <td>2001:df0:680::1</td>
  <td></td>
  <td></td>
</tr>
<tr>
  <td>Hobart</td>
  <td>103.136.100.129</td>
  <td>2001:df0:680:1::1</td>
  <td></td>
  <td></td>
</tr>
<tr>
  <td>Melbourne</td>
  <td>202.77.90.1</td>
  <td>2001:df0:680:6::1</td>
  <td>202.77.90.2</td>
  <td>2001:df0:680:6::2</td>
</tr>
<tr>
  <td>Perth</td>
  <td>103.136.102.1</td>
  <td>2001:df0:680:3::1</td>
  <td>103.136.102.2</td>
  <td>2001:df0:680:3::2</td>
</tr>
<tr>
  <td>Sydney</td>
  <td>202.77.88.1</td>
  <td>2001:df0:680:5::1</td>
  <td>202.77.88.2</td>
  <td>2001:df0:680:5::2</td>
</tr>
</table>




<?php $this->append() ?>
