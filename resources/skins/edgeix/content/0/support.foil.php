<?php
    /** @var Foil\Template\Template $t */
    $this->layout( 'layouts/ixpv4' );
?>

<?php $this->section( 'title' ) ?>
    Support / Contact Details
<?php $this->append() ?>


<?php $this->section('content') ?>

<div class="alert alert-info">
    <h4 align="center">
        Technical Support: <a href="mailto:support@edgeix.net">support@edgeix.net</a>
        &nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;
        Billing / Accounts: <a href="mailto:accounts@edgeix.net">accounts@edgeix.net</a>
        &nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;
        Sales / Marketing: <a href="mailto:marketing@edgeix.net">marketing@edgeix.net</a>
    </h4>
</div>

<br /><br />

<div class="well">

    <table border="0" align="center">
    <tr>
        <td width="20"></td>
        <td colspan="3"><h3>Technical Support Summary</h3></td>
    </tr>
    <tr>
        <td></td>
        <td align="right"><strong>Email:</strong></td>
        <td></td>
        <td align="left"><a href="mailto:support@edgeix.net">support@edgeix.net</a></td>
    </tr>
    <tr>
        <td></td>
        <td align="right"><strong>Phone:</strong></td>
        <td></td>
        <td align="left">+61412005457</td>
    </tr>
    </table>
</div>

<?php $this->append() ?>
