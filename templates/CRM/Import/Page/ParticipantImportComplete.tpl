{crmAPI var='result' entity='Event' action='getsingle' sequential=1 id=$smarty.get.event}
<h3>Registered participation for "{$result.title}"</h3>
<table>
	<tr>
		<th width="50%">Action</th>
		<th>Count</th>
	</tr>
	<tr>
		<td>New contacts</td>
		<td>{$smarty.get.new}</td>
	</tr>
	<tr>
		<td>Existing contacts</td>
		<td>{$smarty.get.existing}</td>
	</tr>
	<tr>
		<td>Manually matched contacts</td>
		<td>{$smarty.get.matched}</td>
	</tr>
	<tr>
		<td><strong>Total</strong></td>
		<td><strong>{math equation="a + b + c" a=$smarty.get.new b=$smarty.get.existing c=$smarty.get.matched}</strong></td>
	</tr>
</table>

Finished!