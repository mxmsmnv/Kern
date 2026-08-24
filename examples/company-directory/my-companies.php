<?php namespace ProcessWire;

/**
 * Example template: /account/companies/
 *
 * Lists company Pages the current user can manage through Kern.
 */

if (!$modules->isInstalled('Kern')) throw new Wire404Exception();
if ($user->isGuest()) $session->redirect('/login/');

/** @var Kern $kern */
$kern = $modules->get('Kern');
$companies = $kern->managedPages($user);

echo '<main>';
echo '<h1>My companies</h1>';
echo '<ul>';

$shown = 0;
foreach ($companies as $company) {
	if ($company->template->name !== 'company' || !$kern->canManage($company, $user)) continue;
	$title = $sanitizer->entities((string)$company->getUnformatted('title'));
	echo '<li><a href="/account/company-edit/?id=' . (int)$company->id . '">' . $title . '</a></li>';
	$shown++;
}

if (!$shown) echo '<li>No companies are available yet.</li>';

echo '</ul>';
echo '</main>';
