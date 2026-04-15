/* eslint-disable @wordpress/no-unsafe-wp-apis */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import {
	Button,
	Modal,
	Spinner,
	CheckboxControl,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
} from '@wordpress/components';

const ShareMediaModal = ({
	setIsShareMediaModalOpen,
	getSelectedCount,
	syncOption,
	brandSites,
	selectedSites,
	handleSiteSelect,
	handleShareMedia,
	getSelectedSitesCount,
	loading,
	setNotice,
}) => {
	const allSelected =
		brandSites.length > 0 && getSelectedSitesCount() === brandSites.length;

	const brandSitesPresent = brandSites.length > 0;

	useEffect(() => {
		if (!brandSitesPresent) {
			setNotice({
				type: 'warning',
				message: __('No brand sites found.', 'onemedia'),
			});
		}
	}, [brandSitesPresent, setNotice]);

	return (
		<Modal
			title={__('Select Sites for Sharing Media', 'onemedia')}
			onRequestClose={() => setIsShareMediaModalOpen(false)}
			shouldCloseOnClickOutside
			size="medium"
			className="onemedia-sites-modal"
		>
			<VStack spacing="4">
				<div className="onemedia-selected-media">
					<h3 className="onemedia-selected-media-heading">
						{sprintf(
							/* translators: %1$d: number of selected media items, %2$s: sync or non-sync mode */
							__(
								'Selected Media: %1$d items (%2$s Mode)',
								'onemedia'
							),
							getSelectedCount(),
							'sync' === syncOption
								? __('Sync', 'onemedia')
								: __('Non Sync', 'onemedia')
						)}
					</h3>
					<p className="onemedia-selected-media-description">
						{__(
							'Select the sites where you want to share these media assets.',
							'onemedia'
						)}
					</p>
				</div>

				{brandSites.length > 0 && (
					<HStack justify="flex-start" spacing="3">
						<CheckboxControl
							label={__('Select All Sites', 'onemedia')}
							checked={allSelected}
							onChange={() => {
								if (allSelected) {
									// Unselect all.
									const reset = {};
									brandSites.forEach((site) => {
										reset[site.url] = false;
									});
									handleSiteSelect(reset, true);
								} else {
									// Select all.
									const selectAll = {};
									brandSites.forEach((site) => {
										selectAll[site.url] = true;
									});
									handleSiteSelect(selectAll, true);
								}
							}}
							__nextHasNoMarginBottom
						/>
						<Button
							className="onemedia-clear-selection-button"
							variant="link"
							onClick={() => {
								const reset = {};
								brandSites.forEach((site) => {
									reset[site.url] = false;
								});
								handleSiteSelect(reset, true);
							}}
							disabled={getSelectedSitesCount() === 0}
						>
							{__('Clear Selection', 'onemedia')}
						</Button>
					</HStack>
				)}

				<div className="onemedia-sites-list">
					{brandSitesPresent && (
						<div className="onemedia-sites-container">
							<VStack spacing="2">
								{brandSites.map((site) => (
									<div
										className="onemedia-site-item"
										key={site.url}
										role="button"
										tabIndex={0}
										onKeyDown={(e) => {
											if (
												e.key === 'Enter' ||
												e.key === ' '
											) {
												e.preventDefault();
												handleSiteSelect(site.url);
											}
										}}
										aria-pressed={!!selectedSites[site.url]}
										onClick={(event) => {
											event.stopPropagation();
											handleSiteSelect(site.url);
										}}
									>
										<CheckboxControl
											className="onemedia-site-checkbox"
											label={
												// Adding an onclick on label to handle checkbox event.
												// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
												<div
													onClick={() =>
														handleSiteSelect(
															site.url
														)
													}
												>
													<div className="onemedia-site-checkbox-item-name">
														{site.name}
													</div>
													<div className="onemedia-site-checkbox-item-url">
														{site.url}
													</div>
												</div>
											}
											checked={!!selectedSites[site.url]}
											onChange={() => {
												// Already handled in parent div's onClick.
											}}
											__nextHasNoMarginBottom
										/>
									</div>
								))}
							</VStack>
						</div>
					)}
				</div>

				<HStack justify="flex-end" spacing="3">
					<Button
						variant="secondary"
						onClick={() => setIsShareMediaModalOpen(false)}
					>
						{__('Cancel', 'onemedia')}
					</Button>
					<Button
						variant="primary"
						onClick={handleShareMedia}
						disabled={getSelectedSitesCount() === 0 || loading}
						isBusy={loading}
					>
						{loading ? <Spinner /> : __('Share Media', 'onemedia')}
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
};

export default ShareMediaModal;

/* eslint-enable @wordpress/no-unsafe-wp-apis */
