<?php namespace ProcessWire;

/**
 * Example template: /claim-company/?id=1234
 *
 * Lets an authenticated user request a company claim or redeem a one-time code.
 */

if (!$modules->isInstalled('Kern')) throw new Wire404Exception();
if ($user->isGuest()) $session->redirect('/login/');

/** @var Kern $kern */
$kern = $modules->get('Kern');
$company = $pages->get((int)$input->get('id'));

if (!$company->id || $company->template->name !== 'company' || !$kern->isPageClaimable($company)) {
	throw new Wire404Exception();
}

$action = $sanitizer->name((string)$input->post('kern_action'));
if ($action !== '') {
	$session->CSRF->validate();

	try {
		if ($action === 'request') {
			if (!$kern->can('request_claim', $company, $user)) {
				throw new WirePermissionException('You cannot request ownership of this company.');
			}
			$claim = $kern->requestClaim(
				$company,
				$user,
				$sanitizer->textarea((string)$input->post('note'))
			);
			$session->message(
				$claim['status'] === 'active'
					? 'Company access is active.'
					: 'Your ownership request is waiting for review.'
			);
		} elseif ($action === 'redeem') {
			$kern->redeemAccessCode(
				$sanitizer->text((string)$input->post('access_code')),
				$user
			);
			$session->message('The access code was accepted. You can now manage this company.');
		} else {
			throw new WireException('Unknown claim action.');
		}
	} catch (WireException $e) {
		$session->error($e->getMessage());
	}

	$session->redirect($page->url . '?id=' . (int)$company->id);
}

$companyTitle = $sanitizer->entities((string)$company->getUnformatted('title'));
$canRequest = $kern->can('request_claim', $company, $user);
$canManage = $kern->canManage($company, $user);

echo '<main>';
echo '<h1>Claim ' . $companyTitle . '</h1>';

if ($canManage) {
	echo '<p>You already have access to this company.</p>';
	echo '<p><a href="/account/company-edit/?id=' . (int)$company->id . '">Edit company information</a></p>';
} elseif ($canRequest) {
	echo '<form method="post">';
	echo $session->CSRF->renderInput();
	echo '<input type="hidden" name="kern_action" value="request">';
	echo '<label for="claim-note">Why should you manage this company?</label>';
	echo '<textarea id="claim-note" name="note" rows="5"></textarea>';
	echo '<button type="submit">Request ownership</button>';
	echo '</form>';
}

echo '<hr>';
echo '<h2>Have an access code?</h2>';
echo '<form method="post">';
echo $session->CSRF->renderInput();
echo '<input type="hidden" name="kern_action" value="redeem">';
echo '<label for="access-code">Access code</label>';
echo '<input id="access-code" name="access_code" type="text" autocomplete="one-time-code" required>';
echo '<button type="submit">Redeem code</button>';
echo '</form>';
echo '</main>';
