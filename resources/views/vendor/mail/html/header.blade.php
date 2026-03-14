<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if(file_exists(public_path('images/EdgeIX-Mark-Brand.png')))
<img src="cid:brand-logo" alt="{{ $slot }}" height="50" style="height: 50px; width: auto; vertical-align: middle; margin-right: 8px;">
@endif
<span style="vertical-align: middle;">{{ $slot }}</span>
</a>
</td>
</tr>
