<?php namespace ProcessWire;

/**
 * Example template: /account/company-edit/?id=1234
 *
 * Submits title and summary changes as a moderated Kern revision.
 */

if (!$modules->isInstalled('Kern')) throw new Wire404Exception();
if ($user->isGuest()) $session->redirect('/login/');

/** @var Kern $kern */
$kern = $modules->get('Kern');
$company = $pages->get((int)$input->get('id'));

if (
	!$company->id
	|| $company->template->name !== 'company'
	|| !$kern->canManage($company, $user)
) {
	throw new Wire404Exception();
}

$editable = $kern->editableFields($company, $user);

if ((string)$input->post('kern_action') === 'submit_revision') {
	$session->CSRF->validate();
	$changes = [];

	if (isset($editable['title'])) {
		$changes['title'] = $sanitizer->text((string)$input->post('title'));
	}
	if (isset($editable['summary'])) {
		$changes['summary'] = $sanitizer->textarea((string)$input->post('summary'));
	}

	try {
		$revision = $kern->submitRevision(
			$company,
			$changes,
			$sanitizer->textarea((string)$input->post('revision_note')),
			$user
		);
		$session->message(
			$revision['status'] === 'approved'
				? 'The company information was updated.'
				: 'Your proposed changes were submitted for review.'
		);
	} catch (WireException $e) {
		$session->error($e->getMessage());
	}

	$session->redirect($page->url . '?id=' . (int)$company->id);
}

$companyTitle = $sanitizer->entities((string)$company->getUnformatted('title'));

echo '<main>';
echo '<h1>Edit ' . $companyTitle . '</h1>';
echo '<p>Your changes will be stored as a revision and will not affect the live Page until approved.</p>';
echo '<form method="post">';
echo $session->CSRF->renderInput();
echo '<input type="hidden" name="kern_action" value="submit_revision">';

if (isset($editable['title'])) {
	echo '<label for="company-title">Company name</label>';
	echo '<input id="company-title" name="title" type="text" value="' . $companyTitle . '" required>';
}

if (isset($editable['summary'])) {
	$summary = $sanitizer->entities((string)$company->getUnformatted('summary'));
	echo '<label for="company-summary">Summary</label>';
	echo '<textarea id="company-summary" name="summary" rows="8">' . $summary . '</textarea>';
}

echo '<label for="revision-note">Note for the moderator</label>';
echo '<textarea id="revision-note" name="revision_note" rows="4"></textarea>';
echo '<button type="submit">Submit changes for review</button>';
echo '</form>';
echo '</main>';
