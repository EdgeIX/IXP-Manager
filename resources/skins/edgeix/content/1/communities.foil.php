<?php
    /** @var Foil\Template\Template $t */
    $this->layout( 'layouts/ixpv4' );
?>

<?php $this->section( 'page-header-preamble' ) ?>
   BGP Communities
<?php $this->append() ?>


<?php $this->section('content') ?>

<h3>EdgeIX Communities</h3>


<p>
  EdgeIX Suppors the following BGP Communities;

</p>

<table border="0">
<tr>
    <td width="50"></td>
    <td align="centre"><strong>Standard, Well Known Communities:</strong></td>
    <td width="20"></td>
</tr>
</table>
<br>
<table style="width:100%" border="0">
<tr>
  <th>Community</th>
  <th>Descritpion</th>
</tr>
<tr>
  <td>0:peer-as</td>
  <td>Prevent announcement of a prefix to a peer</td>
</tr>
<tr>
  <td>24224:peer-as</td>
  <td>Announce a route to a certain peer</td>
</tr>
<tr>
  <td>0:24224</td>
  <td>Prevent announcement of a prefix to all peers</td>
</tr>
<tr>
  <td>24224:24224</td>
  <td>Announce a route to all peers</td>
</tr>
</table>

<br><br>

<table border="0">
<tr>
    <td width="50"></td>
    <td align="centre"><strong>Large Communities:</strong></td>
    <td width="20"></td>
</tr>
</table>
<br>
<table style="width:100%" border="0">
<tr>
  <th>Community</th>
  <th>Descritpion</th>
</tr>
<tr>
  <td>24224:0:peer-as</td>
  <td>Prevent announcement of a prefix to a peer</td>
</tr>
<tr>
  <td>24224:1:peer-as</td>
  <td>Announce a route to a certain peer</td>
</tr>
<tr>
  <td>24224:0:0</td>
  <td>Prevent announcement of a prefix to all peers</td>
</tr>
<tr>
  <td>24224:1:0</td>
  <td>Announce a route to all peers</td>
</tr>
</table>

<br><br>

<table border="0">
<tr>
    <td width="50"></td>
    <td align="centre"><strong>Prepend Communities:</strong></td>
    <td width="20"></td>
</tr>
</table>
<br>
<table style="width:100%" border="0">
<tr>
  <th>Community</th>
  <th>Descritpion</th>
</tr>
<tr>
  <td>24224:101:peer-as</td>
  <td>Prepend to peer AS once</td>
</tr>
<tr>
  <td>24224:102:peer-as</td>
  <td>Prepend to peer AS twice</td>
</tr>
<tr>
  <td>24224:103:peer-as</td>
  <td>Prepend to peer AS thrice</td>
</tr>
</table>

<br><br>

<table border="0">
<tr>
    <td width="50"></td>
    <td align="centre"><strong>Content Opt-in Communities:</strong></td>
    <td width="20"></td>
</tr>
</table>
<br>
<table style="width:100%" border="0">
<tr>
  <th>Community</th>
  <th>Descritpion</th>
</tr>
<tr>
  <td>24305:61290</td>
  <td>Sydney Content Opt-in</td>
</tr>
<tr>
  <td>24305:61390</td>
  <td>Melbourne Content Opt-in</td>
</tr>
<tr>
  <td>24305:61590</td>
  <td>Adelaide Content Opt-in</td>
</tr>
<tr>
  <td>24305:61790</td>
  <td>Brisbane Content Opt-in</td>
</tr>
<tr>
  <td>24305:61890</td>
  <td>Perth Content Opt-in</td>
</tr>

</table>

<?php $this->append() ?>
