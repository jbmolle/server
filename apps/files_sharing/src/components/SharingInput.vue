<!--
  - @copyright Copyright (c) 2019 John Molakvoæ <skjnldsv@protonmail.com>
  -
  - @author John Molakvoæ <skjnldsv@protonmail.com>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
  -->

<template>
	<div class="sharing-search">
		<label for="sharing-search-input">{{ t('files_sharing', 'Search for share recipients') }}</label>
		<NcSelect ref="select"
			id="sharing-search-input"
			class="sharing-search__input"
			:disabled="!canReshare"
			:loading="loading"
			:filterable="false"
			:placeholder="inputPlaceholder"
			:clear-search-on-blur="() => false"
			:user-select="true"
			:options="options"
			v-model="value"
			@search="asyncFind"
			@option:selected="addShare">
			<template #no-options="{ search }">
				{{ search ? noResultText : t('files_sharing', 'No recommendations. Start typing.') }}
			</template>
		</NcSelect>
	</div>
</template>

<script>
import { generateOcsUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import debounce from 'debounce'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'

import Config from '../services/ConfigService'
import GeneratePassword from '../utils/GeneratePassword'
import Share from '../models/Share'
import ShareRequests from '../mixins/ShareRequests'
import ShareTypes from '../mixins/ShareTypes'

export default {
	name: 'SharingInput',

	components: {
		NcSelect,
	},

	mixins: [ShareTypes, ShareRequests],

	props: {
		shares: {
			type: Array,
			default: () => [],
			required: true,
		},
		linkShares: {
			type: Array,
			default: () => [],
			required: true,
		},
		fileInfo: {
			type: Object,
			default: () => {},
			required: true,
		},
		reshare: {
			type: Share,
			default: null,
		},
		canReshare: {
			type: Boolean,
			required: true,
		},
	},

	data() {
		return {
			config: new Config(),
			loading: false,
			query: '',
			recommendations: [],
			ShareSearch: OCA.Sharing.ShareSearch.state,
			suggestions: [],
			value: null,
		}
	},

	computed: {
		/**
		 * Implement ShareSearch
		 * allows external appas to inject new
		 * results into the autocomplete dropdown
		 * Used for the guests app
		 *
		 * @return {Array}
		 */
		externalResults() {
			return this.ShareSearch.results
		},
		inputPlaceholder() {
			const allowRemoteSharing = this.config.isRemoteShareAllowed

			if (!this.canReshare) {
				return t('files_sharing', 'Resharing is not allowed')
			}
			// We can always search with email addresses for users too
			if (!allowRemoteSharing) {
				return t('files_sharing', 'Name or email …')
			}

			return t('files_sharing', 'Name, email, or Federated Cloud ID …')
		},

		isValidQuery() {
			return this.query && this.query.trim() !== '' && this.query.length > this.config.minSearchStringLength
		},

		options() {
			if (this.isValidQuery) {
				return this.suggestions
			}
			return this.recommendations
		},

		noResultText() {
			if (this.loading) {
				return t('files_sharing', 'Searching …')
			}
			return t('files_sharing', 'No elements found.')
		},
	},

	mounted() {
		this.getRecommendations()
	},

	methods: {
		async asyncFind(query) {
			// save current query to check if we display
			// recommendations or search results
			this.query = query.trim()
			if (this.isValidQuery) {
				// start loading now to have proper ux feedback
				// during the debounce
				this.loading = true
				await this.debounceGetSuggestions(query)
			}
		},

		/**
		 * Get suggestions
		 *
		 * @param {string} search the search query
		 * @param {boolean} [lookup=false] search on lookup server
		 */
		async getSuggestions(search, lookup = false) {
			this.loading = true

			if (OC.getCapabilities().files_sharing.sharee.query_lookup_default === true) {
				lookup = true
			}

			const shareType = [
				this.SHARE_TYPES.SHARE_TYPE_USER,
				this.SHARE_TYPES.SHARE_TYPE_GROUP,
				this.SHARE_TYPES.SHARE_TYPE_REMOTE,
				this.SHARE_TYPES.SHARE_TYPE_REMOTE_GROUP,
				this.SHARE_TYPES.SHARE_TYPE_CIRCLE,
				this.SHARE_TYPES.SHARE_TYPE_ROOM,
				this.SHARE_TYPES.SHARE_TYPE_GUEST,
				this.SHARE_TYPES.SHARE_TYPE_DECK,
			]

			if (OC.getCapabilities().files_sharing.public.enabled === true) {
				shareType.push(this.SHARE_TYPES.SHARE_TYPE_EMAIL)
			}

			let request = null
			try {
				request = await axios.get(generateOcsUrl('apps/files_sharing/api/v1/sharees'), {
					params: {
						format: 'json',
						itemType: this.fileInfo.type === 'dir' ? 'folder' : 'file',
						search,
						lookup,
						perPage: this.config.maxAutocompleteResults,
						shareType,
					},
				})
			} catch (error) {
				console.error('Error fetching suggestions', error)
				return
			}

			const data = request.data.ocs.data
			const exact = request.data.ocs.data.exact
			data.exact = [] // removing exact from general results

			// flatten array of arrays
			const rawExactSuggestions = Object.values(exact).reduce((arr, elem) => arr.concat(elem), [])
			const rawSuggestions = Object.values(data).reduce((arr, elem) => arr.concat(elem), [])

			// remove invalid data and format to user-select layout
			const exactSuggestions = this.filterOutExistingShares(rawExactSuggestions)
				.map(share => this.formatForMultiselect(share))
				// sort by type so we can get user&groups first...
				.sort((a, b) => a.shareType - b.shareType)
			const suggestions = this.filterOutExistingShares(rawSuggestions)
				.map(share => this.formatForMultiselect(share))
				// sort by type so we can get user&groups first...
				.sort((a, b) => a.shareType - b.shareType)

			// lookup clickable entry
			// show if enabled and not already requested
			const lookupEntry = []
			if (data.lookupEnabled && !lookup) {
				lookupEntry.push({
					id: 'global-lookup',
					isNoUser: true,
					displayName: t('files_sharing', 'Search globally'),
					lookup: true,
				})
			}

			// if there is a condition specified, filter it
			const externalResults = this.externalResults.filter(result => !result.condition || result.condition(this))

			const allSuggestions = exactSuggestions.concat(suggestions).concat(externalResults).concat(lookupEntry)

			// Count occurrences of display names in order to provide a distinguishable description if needed
			const nameCounts = allSuggestions.reduce((nameCounts, result) => {
				if (!result.displayName) {
					return nameCounts
				}
				if (!nameCounts[result.displayName]) {
					nameCounts[result.displayName] = 0
				}
				nameCounts[result.displayName]++
				return nameCounts
			}, {})

			this.suggestions = allSuggestions.map(item => {
				// Make sure that items with duplicate displayName get the shareWith applied as a description
				if (nameCounts[item.displayName] > 1 && !item.desc) {
					return { ...item, desc: item.shareWithDisplayNameUnique }
				}
				return item
			})

			this.loading = false
			console.info('suggestions', this.suggestions)
		},

		/**
		 * Debounce getSuggestions
		 *
		 * @param {...*} args the arguments
		 */
		debounceGetSuggestions: debounce(function(...args) {
			this.getSuggestions(...args)
		}, 300),

		/**
		 * Get the sharing recommendations
		 */
		async getRecommendations() {
			this.loading = true

			let request = null
			try {
				request = await axios.get(generateOcsUrl('apps/files_sharing/api/v1/sharees_recommended'), {
					params: {
						format: 'json',
						itemType: this.fileInfo.type,
					},
				})
			} catch (error) {
				console.error('Error fetching recommendations', error)
				return
			}

			// Add external results from the OCA.Sharing.ShareSearch api
			const externalResults = this.externalResults.filter(result => !result.condition || result.condition(this))

			// flatten array of arrays
			const rawRecommendations = Object.values(request.data.ocs.data.exact)
				.reduce((arr, elem) => arr.concat(elem), [])

			// remove invalid data and format to user-select layout
			this.recommendations = this.filterOutExistingShares(rawRecommendations)
				.map(share => this.formatForMultiselect(share))
				.concat(externalResults)

			this.loading = false
			console.info('recommendations', this.recommendations)
		},

		/**
		 * Filter out existing shares from
		 * the provided shares search results
		 *
		 * @param {object[]} shares the array of shares object
		 * @return {object[]}
		 */
		filterOutExistingShares(shares) {
			return shares.reduce((arr, share) => {
				// only check proper objects
				if (typeof share !== 'object') {
					return arr
				}
				try {
					if (share.value.shareType === this.SHARE_TYPES.SHARE_TYPE_USER) {
						// filter out current user
						if (share.value.shareWith === getCurrentUser().uid) {
							return arr
						}

						// filter out the owner of the share
						if (this.reshare && share.value.shareWith === this.reshare.owner) {
							return arr
						}
					}

					// filter out existing mail shares
					if (share.value.shareType === this.SHARE_TYPES.SHARE_TYPE_EMAIL) {
						const emails = this.linkShares.map(elem => elem.shareWith)
						if (emails.indexOf(share.value.shareWith.trim()) !== -1) {
							return arr
						}
					} else { // filter out existing shares
						// creating an object of uid => type
						const sharesObj = this.shares.reduce((obj, elem) => {
							obj[elem.shareWith] = elem.type
							return obj
						}, {})

						// if shareWith is the same and the share type too, ignore it
						const key = share.value.shareWith.trim()
						if (key in sharesObj
							&& sharesObj[key] === share.value.shareType) {
							return arr
						}
					}

					// ALL GOOD
					// let's add the suggestion
					arr.push(share)
				} catch {
					return arr
				}
				return arr
			}, [])
		},

		/**
		 * Get the icon based on the share type
		 *
		 * @param {number} type the share type
		 * @return {string} the icon class
		 */
		shareTypeToIcon(type) {
			switch (type) {
			case this.SHARE_TYPES.SHARE_TYPE_GUEST:
				// default is a user, other icons are here to differentiate
				// themselves from it, so let's not display the user icon
				// case this.SHARE_TYPES.SHARE_TYPE_REMOTE:
				// case this.SHARE_TYPES.SHARE_TYPE_USER:
				return {
					icon: 'icon-user',
					iconTitle: t('files_sharing', 'Guest'),
				}
			case this.SHARE_TYPES.SHARE_TYPE_REMOTE_GROUP:
			case this.SHARE_TYPES.SHARE_TYPE_GROUP:
				return {
					icon: 'icon-group',
					iconTitle: t('files_sharing', 'Group'),
				}
			case this.SHARE_TYPES.SHARE_TYPE_EMAIL:
				return {
					icon: 'icon-mail',
					iconTitle: t('files_sharing', 'Email'),
				}
			case this.SHARE_TYPES.SHARE_TYPE_CIRCLE:
				return {
					icon: 'icon-circle',
					iconTitle: t('files_sharing', 'Circle'),
				}
			case this.SHARE_TYPES.SHARE_TYPE_ROOM:
				return {
					icon: 'icon-room',
					iconTitle: t('files_sharing', 'Talk conversation'),
				}
			case this.SHARE_TYPES.SHARE_TYPE_DECK:
				return {
					icon: 'icon-deck',
					iconTitle: t('files_sharing', 'Deck board'),
				}
			default:
				return {}
			}
		},

		/**
		 * Format shares for the multiselect options
		 *
		 * @param {object} result select entry item
		 * @return {object}
		 */
		formatForMultiselect(result) {
			let subtitle
			if (result.value.shareType === this.SHARE_TYPES.SHARE_TYPE_USER && this.config.shouldAlwaysShowUnique) {
				subtitle = result.shareWithDisplayNameUnique ?? ''
			} else if ((result.value.shareType === this.SHARE_TYPES.SHARE_TYPE_REMOTE
					|| result.value.shareType === this.SHARE_TYPES.SHARE_TYPE_REMOTE_GROUP
			) && result.value.server) {
				subtitle = t('files_sharing', 'on {server}', { server: result.value.server })
			} else if (result.value.shareType === this.SHARE_TYPES.SHARE_TYPE_EMAIL) {
				subtitle = result.value.shareWith
			} else {
				subtitle = result.shareWithDescription ?? ''
			}

			return {
				id: `${result.value.shareType}-${result.value.shareWith}`,
				shareWith: result.value.shareWith,
				shareType: result.value.shareType,
				user: result.uuid || result.value.shareWith,
				isNoUser: result.value.shareType !== this.SHARE_TYPES.SHARE_TYPE_USER,
				displayName: result.name || result.label,
				subtitle,
				shareWithDisplayNameUnique: result.shareWithDisplayNameUnique || '',
				...this.shareTypeToIcon(result.value.shareType),
			}
		},

		/**
		 * Process the new share request
		 *
		 * @param {object} value the multiselect option
		 */
		async addShare(value) {
			// Clear the displayed selection
			this.value = null

			if (value.lookup) {
				await this.getSuggestions(this.query, true)

				this.$nextTick(() => {
					// open the dropdown again
					this.$refs.select.$children[0].open = true
				})
				return true
			}

			// handle externalResults from OCA.Sharing.ShareSearch
			if (value.handler) {
				const share = await value.handler(this)
				this.$emit('add:share', new Share(share))
				return true
			}

			this.loading = true
			console.debug('Adding a new share from the input for', value)
			try {
				let password = null

				if (this.config.enforcePasswordForPublicLink
					&& value.shareType === this.SHARE_TYPES.SHARE_TYPE_EMAIL) {
					password = await GeneratePassword()
				}

				const path = (this.fileInfo.path + '/' + this.fileInfo.name).replace('//', '/')
				const share = await this.createShare({
					path,
					shareType: value.shareType,
					shareWith: value.shareWith,
					password,
					permissions: this.fileInfo.sharePermissions & OC.getCapabilities().files_sharing.default_permissions,
					attributes: JSON.stringify(this.fileInfo.shareAttributes),
				})

				// If we had a password, we need to show it to the user as it was generated
				if (password) {
					share.newPassword = password
					// Wait for the newly added share
					const component = await new Promise(resolve => {
						this.$emit('add:share', share, resolve)
					})

					// open the menu on the
					// freshly created share component
					component.open = true
				} else {
					// Else we just add it normally
					this.$emit('add:share', share)
				}

				await this.getRecommendations()
			} catch (error) {
				this.$nextTick(() => {
					// open the dropdown again on error
					this.$refs.select.$children[0].open = true
				})
				this.query = value.shareWith
				console.error('Error while adding new share', error)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style lang="scss">
.sharing-search {
	display: flex;
	flex-direction: column;
	margin-bottom: 4px;

	label[for="sharing-search-input"] {
		margin-bottom: 2px;
	}

	&__input {
		width: 100%;
		margin: 10px 0;
	}
}

.vs__dropdown-menu {
	// properly style the lookup entry
	span[lookup] {
		.avatardiv {
			background-image: var(--icon-search-white);
			background-repeat: no-repeat;
			background-position: center;
			background-color: var(--color-text-maxcontrast) !important;
			div {
				display: none;
			}
		}
	}
}
</style>
