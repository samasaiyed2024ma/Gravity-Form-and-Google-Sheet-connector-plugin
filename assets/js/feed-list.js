/**
 * GFGS Feed List & Editor Controller
 *
 * Registered as the 'gfgs-feed' script handle (depends on 'gfgs-common').
 * Mounts a single-page application into <div id="gfgs-app"> that provides:
 *   - Feed list table with toggle / edit / duplicate / delete.
 *   - Feed editor with account, spreadsheet, sheet, field-map, conditions.
 *   - All AJAX communication with the WordPress back-end.
 *
 * Localized data is injected by GFGS_Addon::feed_list_page() as gfgsData.
 *
 * Custom template syntax (field_type = "custom"):
 *   {28}         Full formatted value of field 28
 *   {28.3}       Raw sub-field value (e.g. quantity, price)
 *   {28:label}   Choice label(s)
 *   {28:value}   Choice value(s)
 *
 * Multi-line templates: one expression per line.
 * Empty lines are skipped in the output.
 *
 * To add a new editor section in the future:
 *   1. Add HTML to renderEditor().
 *   2. Add event binding to bindEditorEvents().
 *   3. Collect its value in saveFeed() and include it in the AJAX payload.
 *
 * @package GFGS
 */

(function ($) {
    'use strict';

	// ── Constants & guards ────────────────────────────────────────────────────

	/** HTML escape helper from admin.js (loaded as a dependency). */
	var esc = window.GFGS.esc;

    /** Localized data from PHP. */
	var DATA = window.gfgsData;

    // Bail if not on the feed page or if the mount element is missing.
	if ( ! DATA || ! $( '#gfgs-app' ).length ) {
		return;
	}

    const AJAX   = DATA && DATA.ajaxUrl; // Wordpress AJAX endpoint
    const NONCE  = DATA && DATA.nonce; // Security token
    const I18N   = (DATA && DATA.i18n)       || {}; // Translated strings
    const EVENTS = (DATA && DATA.feedEvents) || {}; // Feed trigger events (e.g. 'form submit')

	/** @type {jQuery} App mount element. */
	var $app = $( '#gfgs-app' );

  	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Read the current form ID from gfgsData, falling back to the URL ?id param.
	 *
	 * @return {number}
	 */
	function getFormId() {
		if ( DATA && DATA.formId && parseInt( DATA.formId ) > 0 ) {
			return parseInt( DATA.formId );
		}
		var params = new URLSearchParams( window.location.search );
		return parseInt( params.get( 'id' ) || params.get( 'form_id' ) || 0 );
	}

    /**
	 * Perform an AJAX request, logging errors to the console on failure.
	 *
	 * @param {string}   action   wp_ajax_ action slug.
	 * @param {Object}   data     Additional POST parameters.
	 * @param {Function} callback Called with the jQuery AJAX response on success.
	 */
	function feedAjax( action, data, callback ) {
		$.post(
			AJAX,
			Object.assign( { action: action, nonce: NONCE }, data ),
			function ( res ) {
				if ( res.success && callback ) {
					callback( res );
				} else if ( ! res.success ) {
					console.error( '[GFGS] AJAX error:', action, res );
				}
			}
		);
	}

   	// ── App State ─────────────────────────────────────────────────────────────

	/**
	 * Single mutable state object for the SPA.
	 * Mutate this directly; re-render by calling renderFeedList() or renderEditor().
	 *
	 * @type {{
	 *   feeds:        Object[],
	 *   accounts:     Object[],
	 *   fields:       Object[],
	 *   formId:       number,
	 *   editing:      Object|null,
	 *   spreadsheets: Object[],
	 *   sheets:       Object[],
	 *   headers:      string[],
	 *   notice:       {type:string, msg:string}|null
	 * }}
	 */
	var state = {
		feeds:        DATA.feeds    || [],
		accounts:     DATA.accounts || [],
		fields:       DATA.fields   || [],
		formId:       getFormId(),
		editing:      null,
		spreadsheets: [],
		sheets:       [],
		headers:      [],
		notice:       null,
	};

	if ( ! state.formId ) {
		$app.html( '<div class="gfgs-notice error">Could not determine form ID. Please refresh the page.</div>' );
		return;
	}

	// Boot the app.
	renderFeedList();

    // ── Feed List UI ──────────────────────────────────────────────────────────

	/**
	 * Render the main feed list dashboard.
	 */
    function renderFeedList() {
		var html = '<div class="gfgs-header">' +
			'<h2>' + esc( I18N.feedList || 'Google Sheets Feeds' ) + '</h2>' +
			'<div class="gfgs-header-actions">' +
			( ! state.accounts.length
				? '<a href="' + esc( DATA.addAccountUrl ) + '" class="gfgs-btn gfgs-btn-outline">+ Connect Google Account</a>'
				: ''
			) +
			'<button class="gfgs-btn gfgs-btn-primary" id="gfgs-add-feed">+ Add New Feed</button>' +
			'</div>' +
			'</div>';

		if ( state.notice ) {
			html += '<div class="gfgs-notice ' + esc( state.notice.type ) + '"><p>' + esc( state.notice.msg ) + '</p></div>';
			state.notice = null;
		}

		if ( ! state.feeds.length ) {
			html += '<div class="gfgs-empty-state">' +
				'<div class="gfgs-empty-icon">\uD83D\uDCCA</div>' +
				'<h3>No feeds yet</h3>' +
				'<p>Create a feed to start sending form entries to Google Sheets.</p>' +
				'<button class="gfgs-btn gfgs-btn-primary" id="gfgs-add-feed-empty">Create Your First Feed</button>' +
				'</div>';
		} else {
			html += '<div class="gfgs-table-wrap"><table class="gfgs-table">' +
				'<thead><tr>' +
				'<th>Status</th><th>Feed Name</th><th>Spreadsheet / Sheet</th><th>Send On</th>' +
				'</tr></thead><tbody>';

			state.feeds.forEach( function ( feed ) {
				var eventLabel = EVENTS[ feed.send_event ] || feed.send_event || '\u2014';
				var sheetInfo  = feed.sheet_name
					? '<span class="gfgs-sheet-badge">' + esc( feed.sheet_name ) + '</span>'
					: '<span class="gfgs-muted">\u2014</span>';

				html += '<tr data-feed-id="' + feed.id + '">' +
					'<td>' +
						'<button class="gfgs-status-badge ' + ( feed.is_active ? 'gfgs-status-active' : 'gfgs-status-inactive' ) + ' gfgs-toggle-feed"' +
							' data-id="' + feed.id + '" data-active="' + ( feed.is_active ? 1 : 0 ) + '">' +
							( feed.is_active ? 'Active' : 'Inactive' ) +
						'</button>' +
					'</td>' +
					'<td>' +
						'<div class="feed-name-action-container">' +
							'<div class="feed-name">' +
								'<a href="#" class="gfgs-edit-feed gfgs-feed-name-link" data-id="' + feed.id + '"><strong>' + esc( feed.feed_name ) + '</strong></a>' +
							'</div>' +
							'<div class="feed-actions">' +
								'<a class="gfgs-edit-feed" data-id="' + feed.id + '">Edit</a>' +
								' <span>|</span> ' +
								'<a class="gfgs-duplicate-feed" data-id="' + feed.id + '">Duplicate</a>' +
								' <span>|</span> ' +
								'<a class="gfgs-delete-feed" data-id="' + feed.id + '">Delete</a>' +
							'</div>' +
						'</div>' +
					'</td>' +
					'<td>' + sheetInfo + '</td>' +
					'<td><span class="gfgs-event-badge">' + esc( eventLabel ) + '</span></td>' +
					'</tr>';
			} );

			html += '</tbody></table></div>';
		}

		$app.html( html );
		bindListEvents();
	}

	/**
	 * Attach event listeners for the list view (event delegation).
	 */
	function bindListEvents() {
		$app.off();

		$app.on( 'click', '#gfgs-add-feed, #gfgs-add-feed-empty', function () {
			startEditing( newFeed() );
		} );

		$app.on( 'click', '.gfgs-edit-feed', function () {
			var id   = $( this ).data( 'id' );
			var feed = state.feeds.find( function ( f ) { return f.id == id; } );
			if ( feed ) {
				startEditing( JSON.parse( JSON.stringify( feed ) ) );
			}
		} );

		$app.on( 'click', '.gfgs-duplicate-feed', function () {
			var $btn = $( this ).prop( 'disabled', true ).text( 'Duplicating\u2026' );
			var id   = $btn.data( 'id' );

			feedAjax( 'gfgs_duplicate_feed', { feed_id: id }, function ( res ) {
				var newF = res.data.feed;
				state.feeds.push( newF );
				state.notice = { type: 'success', msg: '"' + newF.feed_name + '" created successfully.' };
				renderFeedList();
			} );
		} );

		$app.on( 'click', '.gfgs-delete-feed', function () {
			if ( ! confirm( I18N.confirmDel || 'Delete this feed?' ) ) {
				return;
			}
			var id = $( this ).data( 'id' );
			feedAjax( 'gfgs_delete_feed', { feed_id: id }, function () {
				state.feeds = state.feeds.filter( function ( f ) { return f.id != id; } );
				state.notice = { type: 'success', msg: 'Feed deleted successfully.' };
				renderFeedList();
			} );
		} );

		$app.on( 'click', '.gfgs-toggle-feed', function () {
			var $btn      = $( this );
			var id        = $btn.data( 'id' );
			var newActive = $btn.data( 'active' ) == 1 ? 0 : 1;

			feedAjax( 'gfgs_toggle_feed', { feed_id: id, active: newActive }, function () {
				var f = state.feeds.find( function ( f ) { return f.id == id; } );
				if ( f ) { f.is_active = newActive; }

				$btn.data( 'active', newActive )
					.text( newActive ? 'Active' : 'Inactive' )
					.removeClass( 'gfgs-status-active gfgs-status-inactive' )
					.addClass( newActive ? 'gfgs-status-active' : 'gfgs-status-inactive' );
			} );
		} );
	}

    // ── Feed Editor Logic ─────────────────────────────────────────────────────

	/**
	 * Return a blank feed object for the "Add New Feed" flow.
	 *
	 * @return {Object}
	 */
	function newFeed() {
		return {
			id:             0,
			form_id:        state.formId,
			feed_name:      '',
			is_active:      1,
			account_id:     state.accounts.length ? state.accounts[ 0 ].id : '',
			spreadsheet_id: '',
			sheet_id:       '',
			sheet_name:     '',
			send_event:     'form_submit',
			field_map:      [],
			date_formats:   {},
			conditions:     { enabled: false, action: 'send', logic: 'all', rules: [] },
		};
	}

	/**
	 * Switch from the list view to the editor view for a given feed.
	 *
	 * @param {Object} feed - Feed object to edit (deep-cloned by callers).
	 */
	function startEditing( feed ) {
		state.editing      = feed;
		state.spreadsheets = [];
		state.sheets       = [];
		state.headers      = [];
		renderEditor();
	}

	/**
	 * Build and mount the feed editor HTML.
	 */
	function renderEditor() {
		var feed        = state.editing;
		var isNew       = ! feed.id;
		var accounts    = state.accounts;
		var dateFormats = feed.date_formats || {};
		var isActive    = feed.is_active ? 1 : 0;

		// Account select options.
		var accountOptions = '<option value="">— Select Account —</option>';
		accounts.forEach( function ( a ) {
			accountOptions += '<option value="' + a.id + '"' + ( feed.account_id == a.id ? ' selected' : '' ) + '>' + esc( a.account_name || a.email ) + '</option>';
		} );

		// Spreadsheet select options.
		var ssOptions = '<option value="">— Select Spreadsheet —</option>';
		state.spreadsheets.forEach( function ( ss ) {
			ssOptions += '<option value="' + esc( ss.id ) + '"' + ( feed.spreadsheet_id == ss.id ? ' selected' : '' ) + '>' + esc( ss.name ) + '</option>';
		} );

		// Sheet tab select options.
		var sheetOptions = '<option value="">— Select Sheet Tab —</option>';
		state.sheets.forEach( function ( sh ) {
			sheetOptions += '<option value="' + esc( sh.title ) + '"' + ( feed.sheet_name == sh.title ? ' selected' : '' ) + '>' + esc( sh.title ) + '</option>';
		} );

		// Send event options (built from localised EVENTS map).
		var eventOptions = Object.keys( EVENTS ).map( function ( key ) {
			return '<option value="' + key + '"' + ( feed.send_event === key ? ' selected' : '' ) + '>' + esc( EVENTS[ key ] ) + '</option>';
		} ).join( '' );

		var fieldMapHtml   = renderFieldMap( feed.field_map, state.headers, state.fields, dateFormats );
		var conditionsHtml = renderConditions( feed.conditions, state.fields );

		var html = '<div class="gfgs-editor">' +

			'<div class="gfgs-editor-header">' +
				'<button class="gfgs-btn gfgs-btn-ghost" id="gfgs-back">\u2190 Back to Feeds</button>' +
				'<h2>' + ( isNew ? 'New Feed' : 'Edit Feed' ) + '</h2>' +
				'<button class="gfgs-status-badge ' + ( isActive ? 'gfgs-status-active' : 'gfgs-status-inactive' ) + ' gfgs-toggle-feed"' +
					' data-id="' + feed.id + '" data-active="' + isActive + '" id="gfgs-status-toggle">' +
					( isActive ? 'Active' : 'Inactive' ) +
				'</button>' +
			'</div>' +

			'<div class="gfgs-editor-body">' +

				'<!-- Feed Name -->' +
				'<div class="gfgs-section">' +
					'<div class="gfgs-section-title">Feed Name</div>' +
					'<input type="text" id="gfgs-feed-name" class="gfgs-input" value="' + esc( feed.feed_name ) + '" placeholder="e.g. Contact Form Responses">' +
				'</div>' +

				'<!-- Google Account -->' +
				'<div class="gfgs-section">' +
					'<div class="gfgs-section-title">Google Account</div>' +
					( accounts.length === 0
						? '<p class="gfgs-hint">No accounts connected. <a href="' + esc( DATA.addAccountUrl ) + '">Connect one here</a>.</p>'
						: '<select id="gfgs-account-select" class="gfgs-select">' + accountOptions + '</select>'
					) +
				'</div>' +

				'<!-- Spreadsheet -->' +
				'<div class="gfgs-section">' +
					'<div class="gfgs-section-title">Spreadsheet</div>' +
					'<div class="gfgs-row-inline">' +
						'<select id="gfgs-spreadsheet-select" class="gfgs-select"' + ( ! accounts.length ? ' disabled' : '' ) + '>' + ssOptions + '</select>' +
						'<button class="gfgs-btn gfgs-btn-refresh" id="gfgs-refresh-spreadsheets">\u21BB Refresh Spreadsheets</button>' +
						'<span class="gfgs-spinner" id="gfgs-ss-spinner" style="display:none"></span>' +
					'</div>' +
				'</div>' +

				'<!-- Sheet Tab -->' +
				'<div class="gfgs-section">' +
					'<div class="gfgs-section-title">Sheet Tab</div>' +
					'<div class="gfgs-row-inline">' +
						'<select id="gfgs-sheet-select" class="gfgs-select"' + ( ! feed.spreadsheet_id ? ' disabled' : '' ) + '>' + sheetOptions + '</select>' +
						'<span class="gfgs-spinner" id="gfgs-sh-spinner" style="display:none"></span>' +
					'</div>' +
				'</div>' +

				'<!-- Send Trigger -->' +
				'<div class="gfgs-section">' +
					'<div class="gfgs-section-title">When to Send</div>' +
					'<select id="gfgs-send-event" class="gfgs-select">' + eventOptions + '</select>' +
				'</div>' +

				'<!-- Field Mapping -->' +
				'<div class="gfgs-section">' +
					'<div class="gfgs-section-title">Field Mapping' +
						'<div class="gfgs-section-title-actions">' +
							'<button class="gfgs-btn gfgs-btn-refresh" id="gfgs-refresh-fields">\u21BB Refresh Sheet Columns</button>' +
							'<span class="gfgs-spinner" id="gfgs-hd-spinner" style="display:none"></span>' +
						'</div>' +
					'</div>' +
					'<div id="gfgs-field-mapper">' + fieldMapHtml + '</div>' +
					'<div id="gfgs-add-field-row" class="gfgs-add-field-row"' + ( state.headers.length ? '' : ' style="display:none"' ) + '>' +
						'<select id="gfgs-new-column-select" class="gfgs-select gfgs-select-sm">' +
							'<option value="">— Add Column —</option>' +
							renderUnmappedColumnOptions( feed.field_map, state.headers ) +
						'</select>' +
						'<button class="gfgs-btn gfgs-btn-outline gfgs-btn-sm" id="gfgs-add-mapping">+ Add Field</button>' +
					'</div>' +
				'</div>' +

				'<!-- Conditional Logic -->' +
				'<div class="gfgs-section">' +
					'<div class="gfgs-section-title">Conditional Logic</div>' +
					'<div id="gfgs-conditions">' + conditionsHtml + '</div>' +
				'</div>' +

			'</div><!-- .gfgs-editor-body -->' +

			'<div class="gfgs-editor-footer">' +
				'<button class="gfgs-btn gfgs-btn-primary" id="gfgs-save-feed">Save Feed</button>' +
				'<span class="gfgs-save-status" id="gfgs-save-status"></span>' +
			'</div>' +
		'</div>';

		$app.html( html );
		bindEditorEvents();

		// Auto-load cascading dropdowns for existing feeds.
		if ( feed.account_id && ! state.spreadsheets.length ) {
			loadSpreadsheets( feed.account_id, function () {
				if ( feed.spreadsheet_id && ! state.sheets.length ) {
					loadSheets( feed.account_id, feed.spreadsheet_id, function () {
						if ( feed.sheet_name && ! state.headers.length ) {
							loadHeaders( feed.account_id, feed.spreadsheet_id, feed.sheet_name );
						}
					} );
				}
			} );
		}
	}

    // ── Field Map Rendering ───────────────────────────────────────────────────

	/**
	 * Build the field-mapping rows HTML.
	 *
	 * When headers are loaded, they define the column list.
	 * Otherwise the saved field_map columns are used (edit existing feed).
	 *
	 * @param  {Object[]} fieldMap    Saved field map array.
	 * @param  {string[]} headers     Sheet header strings.
	 * @param  {Object[]} formFields  GF form fields.
	 * @param  {Object}   dateFormats Column → PHP date format string map.
	 * @return {string}   HTML markup.
	 */
	function renderFieldMap( fieldMap, headers, formFields, dateFormats ) {
		if ( ! headers.length && ! fieldMap.length ) {
			return '<p class="gfgs-hint">Select a sheet tab above to load columns and map your form fields.</p>';
		}

		var columns = headers.length
			? headers
			: fieldMap.map( function ( m ) { return m.sheet_column || m.column; } );

		var html = '';

		columns.forEach( function ( col ) {
			var mapping    = fieldMap.find( function ( m ) { return ( m.sheet_column || m.column ) === col; } ) || {};
			var fieldId    = mapping.field_id   || mapping.gf_field || '';
			var fieldType  = mapping.field_type || 'standard';
			var dateFormat = ( dateFormats && dateFormats[ col ] ) || 'Y-m-d';

			html += '<div class="gfgs-mapper-row" data-column="' + esc( col ) + '">' +
				'<div class="gfgs-mapper-col-name">' + esc( col ) + '</div>' +
				'<div class="gfgs-mapper-controls">' +
					'<select class="gfgs-select gfgs-select-sm gfgs-field-type-select">' +
						'<option value="standard"' + ( fieldType === 'standard' ? ' selected' : '' ) + '>Standard Field</option>' +
						'<option value="meta"'     + ( fieldType === 'meta'     ? ' selected' : '' ) + '>Entry Meta</option>' +
						'<option value="custom"'   + ( fieldType === 'custom'   ? ' selected' : '' ) + '>Custom Value</option>' +
					'</select>' +
					renderFieldSelector( fieldType, fieldId, formFields, col ) +
				'</div>' +
				renderDateFormatRow( fieldId, fieldType, formFields, dateFormat, col ) +
				'<button class="gfgs-btn-icon gfgs-remove-mapping" data-col="' + esc( col ) + '" title="Remove mapping">\u2715</button>' +
			'</div>';
		} );

		return html || '<p class="gfgs-hint">No columns found. Refresh the sheet columns above.</p>';
	}

	/**
	 * Render the field selector control for one mapping row.
	 *
	 * @param  {string}   fieldType  'standard' | 'meta' | 'custom'
	 * @param  {string}   fieldId    Currently selected field ID or template string.
	 * @param  {Object[]} formFields GF form fields.
	 * @param  {string}   col        Sheet column name (for placeholder context).
	 * @return {string}   HTML markup.
	 */
	function renderFieldSelector( fieldType, fieldId, formFields, col ) {
		if ( fieldType === 'custom' ) {
			return '<textarea class="gfgs-input gfgs-textarea-sm gfgs-custom-value" rows="3"' +
				' placeholder="e.g. {5} or multi-line:&#10;{26:label} - {28.3}&#10;{26:label} - {29.3}">' +
				esc( fieldId ) +
				'</textarea>';
		}

		if ( fieldType === 'meta' ) {
			var metaFields = [
				{ id: 'entry_id',       label: 'Entry ID' },
				{ id: 'date_created',   label: 'Date Created' },
				{ id: 'source_url',     label: 'Source URL' },
				{ id: 'user_ip',        label: 'User IP' },
				{ id: 'created_by',     label: 'Created By (User ID)' },
				{ id: 'payment_status', label: 'Payment Status' },
			];
			var metaOpts = '<option value="">— Select Meta —</option>';
			metaFields.forEach( function ( m ) {
				metaOpts += '<option value="' + m.id + '"' + ( fieldId === m.id ? ' selected' : '' ) + '>' + m.label + '</option>';
			} );
			return '<select class="gfgs-select gfgs-select-sm gfgs-field-select">' + metaOpts + '</select>';
		}

		// Standard field.
		var opts = '<option value="">— Select Form Field —</option>';
		formFields.forEach( function ( f ) {
			opts += '<option value="' + esc( f.id ) + '"' + ( fieldId == f.id ? ' selected' : '' ) + '>' + esc( f.label ) + '</option>';
		} );
		return '<select class="gfgs-select gfgs-select-sm gfgs-field-select">' + opts + '</select>';
	}

	/**
	 * Render the date-format select row (only for date fields).
	 *
	 * @param  {string}   fieldId    Currently selected field ID.
	 * @param  {string}   fieldType  'standard' | 'meta' | 'custom'.
	 * @param  {Object[]} formFields GF form fields.
	 * @param  {string}   dateFormat Currently selected format string.
	 * @param  {string}   col        Sheet column name (for data-col attribute).
	 * @return {string}   HTML markup, or empty string when not applicable.
	 */
	function renderDateFormatRow( fieldId, fieldType, formFields, dateFormat, col ) {
		var field  = formFields.find( function ( f ) { return f.id == fieldId; } );
		var isDate = field && field.type === 'date';

		if ( ! isDate || fieldType !== 'standard' ) {
			return '';
		}

		var formats = [
			{ value: 'Y-m-d',     label: 'YYYY-MM-DD (2024-01-31)' },
			{ value: 'm/d/Y',     label: 'MM/DD/YYYY (01/31/2024)' },
			{ value: 'd/m/Y',     label: 'DD/MM/YYYY (31/01/2024)' },
			{ value: 'd-m-Y',     label: 'DD-MM-YYYY (31-01-2024)' },
			{ value: 'F j, Y',    label: 'January 31, 2024' },
			{ value: 'j F Y',     label: '31 January 2024' },
			{ value: 'timestamp', label: 'Unix Timestamp' },
		];

		var opts = formats.map( function ( f ) {
			return '<option value="' + f.value + '"' + ( dateFormat === f.value ? ' selected' : '' ) + '>' + f.label + '</option>';
		} ).join( '' );

		return '<div class="gfgs-date-format-row">' +
			'<span class="gfgs-hint-inline">\uD83D\uDCC5 Date format:</span>' +
			'<select class="gfgs-select gfgs-select-xs gfgs-date-format" data-col="' + esc( col ) + '">' + opts + '</select>' +
		'</div>';
	}

	/**
	 * Return <option> elements for sheet columns not yet in the field map.
	 *
	 * @param  {Object[]} fieldMap Saved field map array.
	 * @param  {string[]} headers  Sheet header strings.
	 * @return {string}   HTML option elements.
	 */
	function renderUnmappedColumnOptions( fieldMap, headers ) {
		var mapped = fieldMap.map( function ( m ) { return m.sheet_column || m.column; } );
		return headers
			.filter( function ( h ) { return mapped.indexOf( h ) === -1; } )
			.map( function ( h ) { return '<option value="' + esc( h ) + '">' + esc( h ) + '</option>'; } )
			.join( '' );
	}

    // ── Conditions Rendering ──────────────────────────────────────────────────

	/**
	 * Build the conditional logic section HTML.
	 *
	 * @param  {Object}   conditions Feed conditions object.
	 * @param  {Object[]} formFields GF form fields.
	 * @return {string}   HTML markup.
	 */
	function renderConditions( conditions, formFields ) {
		var enabled  = conditions && conditions.enabled;
		var logic    = ( conditions && conditions.logic ) || 'all';
		var rules    = ( conditions && conditions.rules ) || [];
		var rulesHtml = '';

		rules.forEach( function ( rule, i ) {
			rulesHtml += renderConditionRule( rule, i, formFields );
		} );

		return '<div class="gfgs-conditions-wrap">' +
			'<label class="gfgs-checkbox-label">' +
				'<input type="checkbox" id="gfgs-cond-enabled"' + ( enabled ? ' checked' : '' ) + '>' +
				'Enable conditional logic (only send when conditions are met)' +
			'</label>' +
			'<div id="gfgs-cond-body"' + ( enabled ? '' : ' style="display:none"' ) + '>' +
				'<div class="gfgs-cond-header">Send this feed when ' +
					'<select id="gfgs-cond-logic" class="gfgs-select gfgs-select-xs">' +
						'<option value="all"' + ( logic === 'all' ? ' selected' : '' ) + '>ALL</option>' +
						'<option value="any"' + ( logic === 'any' ? ' selected' : '' ) + '>ANY</option>' +
					'</select>' +
					' of the following match:' +
				'</div>' +
				'<div id="gfgs-cond-rules">' + rulesHtml + '</div>' +
				'<button class="gfgs-btn gfgs-btn-outline gfgs-btn-sm" id="gfgs-add-rule">+ Add Condition</button>' +
			'</div>' +
		'</div>';
	}

	/**
	 * Build one condition rule row.
	 *
	 * @param  {Object}   rule       Single condition rule {field_id, operator, value}.
	 * @param  {number}   index      Row index (used as data-index).
	 * @param  {Object[]} formFields GF form fields.
	 * @return {string}   HTML markup.
	 */
	function renderConditionRule( rule, index, formFields ) {
		var fieldId  = rule.field_id || '';
		var operator = rule.operator || 'is';
		var value    = rule.value    || '';

		var fieldOpts = '<option value="">— Field —</option>';
		formFields.forEach( function ( f ) {
			fieldOpts += '<option value="' + esc( f.id ) + '"' + ( fieldId == f.id ? ' selected' : '' ) + '>' + esc( f.label ) + '</option>';
		} );

		var operators = [
			{ v: 'is',          l: 'is' },
			{ v: 'isnot',       l: 'is not' },
			{ v: 'contains',    l: 'contains' },
			{ v: 'starts_with', l: 'starts with' },
			{ v: 'ends_with',   l: 'ends with' },
			{ v: '>',           l: 'greater than' },
			{ v: '<',           l: 'less than' },
		];

		var opOpts = operators.map( function ( o ) {
			return '<option value="' + o.v + '"' + ( operator === o.v ? ' selected' : '' ) + '>' + o.l + '</option>';
		} ).join( '' );

		var field      = state.fields.find( function ( f ) { return f.id == fieldId; } );
		var valueInput = renderConditionValueInput( field, value, index );

		return '<div class="gfgs-cond-rule" data-index="' + index + '">' +
			'<select class="gfgs-select gfgs-select-sm gfgs-cond-field">' + fieldOpts + '</select>' +
			'<select class="gfgs-select gfgs-select-sm gfgs-cond-operator">' + opOpts + '</select>' +
			'<div class="gfgs-cond-value-wrap">' + valueInput + '</div>' +
			'<button class="gfgs-btn-icon gfgs-remove-rule" title="Remove">\u2715</button>' +
		'</div>';
	}

	/**
	 * Render a condition value input — a select for choice fields, text otherwise.
	 *
	 * @param  {Object|null} field  GF field object or null.
	 * @param  {string}      value  Currently selected / entered value.
	 * @param  {number}      index  Rule index.
	 * @return {string}      HTML markup.
	 */
	function renderConditionValueInput( field, value, index ) {
		if ( field && field.choices && field.choices.length ) {
			var opts = '<option value="">— Select —</option>';
			field.choices.forEach( function ( c ) {
				opts += '<option value="' + esc( c.value ) + '"' + ( value === c.value ? ' selected' : '' ) + '>' + esc( c.text || c.value ) + '</option>';
			} );
			return '<select class="gfgs-select gfgs-select-sm gfgs-cond-value">' + opts + '</select>';
		}
		return '<input type="text" class="gfgs-input gfgs-cond-value" value="' + esc( value ) + '" placeholder="Value">';
	}

    // ── Editor Events ─────────────────────────────────────────────────────────

	/**
	 * Attach all event listeners needed by the editor view.
	 */
	function bindEditorEvents() {
		$app.off();

		// Back to list.
		$app.on( 'click', '#gfgs-back', function () {
			state.editing = null;
			renderFeedList();
		} );

		// Status toggle in editor header (syncs state; persisted on Save).
		$app.on( 'click', '#gfgs-status-toggle', function () {
			var $btn      = $( this );
			var newActive = $btn.data( 'active' ) == 1 ? 0 : 1;
			state.editing.is_active = newActive;
			$btn.data( 'active', newActive )
				.text( newActive ? 'Active' : 'Inactive' )
				.removeClass( 'gfgs-status-active gfgs-status-inactive' )
				.addClass( newActive ? 'gfgs-status-active' : 'gfgs-status-inactive' );
		} );

		// Account change → reload spreadsheets.
		$app.on( 'change', '#gfgs-account-select', function () {
			var accountId = $( this ).val();
			state.editing.account_id     = accountId;
			state.editing.spreadsheet_id = '';
			state.editing.sheet_name     = '';
			state.spreadsheets = [];
			state.sheets       = [];
			state.headers      = [];
			if ( accountId ) { loadSpreadsheets( accountId ); }
		} );

		// Refresh spreadsheets button.
		$app.on( 'click', '#gfgs-refresh-spreadsheets', function () {
			var accountId = $( '#gfgs-account-select' ).val();
			if ( ! accountId ) { return; }
			state.spreadsheets = [];
			loadSpreadsheets( accountId );
		} );

		// Spreadsheet change → load sheet tabs.
		$app.on( 'change', '#gfgs-spreadsheet-select', function () {
			var ssId      = $( this ).val();
			var accountId = $( '#gfgs-account-select' ).val();
			state.editing.spreadsheet_id = ssId;
			state.editing.sheet_name     = '';
			state.sheets  = [];
			state.headers = [];
			$( '#gfgs-sheet-select' ).html( '<option value="">— Select Sheet Tab —</option>' ).prop( 'disabled', ! ssId );
			refreshFieldMapper();
			if ( ssId && accountId ) { loadSheets( accountId, ssId ); }
		} );

		// Sheet tab change → load headers.
		$app.on( 'change', '#gfgs-sheet-select', function () {
			var sheetName = $( this ).val();
			var ssId      = $( '#gfgs-spreadsheet-select' ).val();
			var accountId = $( '#gfgs-account-select' ).val();
			state.editing.sheet_name = sheetName;
			state.headers = [];
			refreshFieldMapper();
			if ( sheetName && ssId && accountId ) { loadHeaders( accountId, ssId, sheetName ); }
		} );

		// Refresh sheet columns button.
		$app.on( 'click', '#gfgs-refresh-fields', function () {
			var accountId = $( '#gfgs-account-select' ).val();
			var ssId      = $( '#gfgs-spreadsheet-select' ).val();
			var sheetName = $( '#gfgs-sheet-select' ).val();
			if ( ! accountId || ! ssId || ! sheetName ) { return; }
			state.headers = [];
			loadHeaders( accountId, ssId, sheetName );
		} );

		// Field type change → re-render the field selector control.
		$app.on( 'change', '.gfgs-field-type-select', function () {
			var $row      = $( this ).closest( '.gfgs-mapper-row' );
			var col       = $row.data( 'column' );
			var type      = $( this ).val();
			var $controls = $row.find( '.gfgs-mapper-controls' );
			$controls.find( '.gfgs-field-select, .gfgs-custom-value, .gfgs-textarea-sm, .gfgs-hint' ).remove();
			$controls.append( renderFieldSelector( type, '', state.fields, col ) );
			$row.find( '.gfgs-date-format-row' ).remove();
		} );

		// Field select change → show/hide date format row.
		$app.on( 'change', '.gfgs-field-select', function () {
			var $row    = $( this ).closest( '.gfgs-mapper-row' );
			var col     = $row.data( 'column' );
			var fieldId = $( this ).val();
			var field   = state.fields.find( function ( f ) { return f.id == fieldId; } );
			$row.find( '.gfgs-date-format-row' ).remove();
			if ( field && field.type === 'date' ) {
				var dateFormat = ( state.editing.date_formats && state.editing.date_formats[ col ] ) || 'Y-m-d';
				$row.find( '.gfgs-mapper-controls' ).after(
					renderDateFormatRow( fieldId, 'standard', state.fields, dateFormat, col )
				);
			}
		} );

		// Remove a mapping row.
		$app.on( 'click', '.gfgs-remove-mapping', function () {
			var col = $( this ).data( 'col' );
			state.editing.field_map = state.editing.field_map.filter( function ( m ) {
				return ( m.sheet_column || m.column ) !== col;
			} );
			$( this ).closest( '.gfgs-mapper-row' ).remove();
		} );

		// Add a new mapping row from the column select.
		$app.on( 'click', '#gfgs-add-mapping', function () {
			var col = $( '#gfgs-new-column-select' ).val();
			if ( ! col ) { return; }

			state.editing.field_map.push( { sheet_column: col, field_id: '', field_type: 'standard' } );

			var rowHtml = '<div class="gfgs-mapper-row" data-column="' + esc( col ) + '">' +
				'<div class="gfgs-mapper-col-name">' + esc( col ) + '</div>' +
				'<div class="gfgs-mapper-controls">' +
					'<select class="gfgs-select gfgs-select-sm gfgs-field-type-select">' +
						'<option value="standard" selected>Standard Field</option>' +
						'<option value="meta">Entry Meta</option>' +
						'<option value="custom">Custom Value</option>' +
					'</select>' +
					renderFieldSelector( 'standard', '', state.fields, col ) +
				'</div>' +
				'<button class="gfgs-btn-icon gfgs-remove-mapping" data-col="' + esc( col ) + '" title="Remove">\u2715</button>' +
			'</div>';

			$( '#gfgs-field-mapper' ).append( rowHtml );
			$( '#gfgs-field-mapper .gfgs-hint' ).remove();
		} );

		// Conditions enable/disable toggle.
		$app.on( 'change', '#gfgs-cond-enabled', function () {
			$( '#gfgs-cond-body' ).toggle( $( this ).is( ':checked' ) );
		} );

		// Add a condition rule.
		$app.on( 'click', '#gfgs-add-rule', function () {
			var newRule = { field_id: '', operator: 'is', value: '' };
			var index   = $( '#gfgs-cond-rules .gfgs-cond-rule' ).length;
			$( '#gfgs-cond-rules' ).append( renderConditionRule( newRule, index, state.fields ) );
		} );

		// Remove a condition rule.
		$app.on( 'click', '.gfgs-remove-rule', function () {
			$( this ).closest( '.gfgs-cond-rule' ).remove();
		} );

		// Condition field change → re-render value input to match field choices.
		$app.on( 'change', '.gfgs-cond-field', function () {
			var $rule   = $( this ).closest( '.gfgs-cond-rule' );
			var index   = $rule.data( 'index' );
			var fieldId = $( this ).val();
			var field   = state.fields.find( function ( f ) { return f.id == fieldId; } );
			$rule.find( '.gfgs-cond-value-wrap' ).html( renderConditionValueInput( field, '', index ) );
		} );

		// Save feed.
		$app.on( 'click', '#gfgs-save-feed', saveFeed );
	}

	/**
	 * Re-render only the field-mapper section without a full editor re-render.
	 */
	function refreshFieldMapper() {
		var feed        = state.editing;
		var dateFormats = feed.date_formats || {};
		$( '#gfgs-field-mapper' ).html( renderFieldMap( feed.field_map, state.headers, state.fields, dateFormats ) );
	}

    // ── Data Collection ───────────────────────────────────────────────────────

	/**
	 * Read field-map rows from the DOM into a plain array.
	 *
	 * @return {Object[]}  Array of {sheet_column, field_id, field_type} objects.
	 */
	function collectFieldMap() {
		var rows = [];
		$( '#gfgs-field-mapper .gfgs-mapper-row' ).each( function () {
			var col       = $( this ).data( 'column' );
			var fieldType = $( this ).find( '.gfgs-field-type-select' ).val() || 'standard';
			var fieldId   = fieldType === 'custom'
				? $( this ).find( '.gfgs-custom-value' ).val() || ''
				: $( this ).find( '.gfgs-field-select' ).val() || '';
			if ( col ) {
				rows.push( { sheet_column: col, field_id: fieldId, field_type: fieldType } );
			}
		} );
		return rows;
	}

	/**
	 * Read date-format selects from the DOM.
	 *
	 * @return {Object}  Map of column → format string.
	 */
	function collectDateFormats() {
		var formats = {};
		$( '.gfgs-date-format' ).each( function () {
			var col = $( this ).data( 'col' );
			if ( col ) { formats[ col ] = $( this ).val(); }
		} );
		return formats;
	}

	/**
	 * Read condition rules from the DOM.
	 *
	 * @return {Object}  Conditions object {enabled, logic, action, rules[]}.
	 */
	function collectConditions() {
		var enabled = $( '#gfgs-cond-enabled' ).is( ':checked' );
		var logic   = $( '#gfgs-cond-logic' ).val() || 'all';
		var rules   = [];

		$( '#gfgs-cond-rules .gfgs-cond-rule' ).each( function () {
			var fieldId  = $( this ).find( '.gfgs-cond-field' ).val();
			var operator = $( this ).find( '.gfgs-cond-operator' ).val();
			var value    = $( this ).find( '.gfgs-cond-value' ).val();
			if ( fieldId ) {
				rules.push( { field_id: fieldId, operator: operator, value: value } );
			}
		} );

		return { enabled: enabled, logic: logic, action: 'send', rules: rules };
	}

	// ── Save feed ─────────────────────────────────────────────────────────────

	/**
	 * Collect all editor values and POST them to the gfgs_save_feed endpoint.
	 * Updates local state with the response and shows success/error feedback.
	 */
	function saveFeed() {
		var $btn    = $( '#gfgs-save-feed' );
		var $status = $( '#gfgs-save-status' );
		var feed    = state.editing;

		var feedName = $( '#gfgs-feed-name' ).val().trim();
		if ( ! feedName ) {
			alert( 'Please enter a feed name.' );
			return;
		}

		var fieldMap    = collectFieldMap();
		var dateFormats = collectDateFormats();
		var conditions  = collectConditions();
		var isActive    = state.editing.is_active ? 1 : 0;

		$btn.prop( 'disabled', true ).text( 'Saving\u2026' );
		$status.text( '' ).removeClass( 'success error' );

		$.post(
			AJAX,
			{
				action:         'gfgs_save_feed',
				nonce:          NONCE,
				id:             feed.id || 0,
				form_id:        state.formId,
				feed_name:      feedName,
				account_id:     $( '#gfgs-account-select' ).val() || feed.account_id,
				spreadsheet_id: $( '#gfgs-spreadsheet-select' ).val() || feed.spreadsheet_id,
				sheet_id:       feed.sheet_id || '',
				sheet_name:     $( '#gfgs-sheet-select' ).val() || feed.sheet_name,
				send_event:     $( '#gfgs-send-event' ).val(),
				is_active:      isActive,
				field_map:      JSON.stringify( fieldMap ),
				date_formats:   JSON.stringify( dateFormats ),
				conditions:     JSON.stringify( conditions ),
			},
			function ( res ) {
				$btn.prop( 'disabled', false ).text( 'Save Feed' );

				if ( res.success ) {
					var savedId     = res.data.feed_id;
					var existingIdx = state.feeds.findIndex( function ( f ) { return f.id == feed.id; } );
					var updatedFeed = Object.assign( {}, feed, {
						id:             savedId,
						feed_name:      feedName,
						account_id:     $( '#gfgs-account-select' ).val(),
						spreadsheet_id: $( '#gfgs-spreadsheet-select' ).val(),
						sheet_name:     $( '#gfgs-sheet-select' ).val(),
						send_event:     $( '#gfgs-send-event' ).val(),
						is_active:      isActive,
						field_map:      fieldMap,
						date_formats:   dateFormats,
						conditions:     conditions,
					} );

					if ( existingIdx > -1 ) {
						state.feeds[ existingIdx ] = updatedFeed;
					} else {
						state.feeds.push( updatedFeed );
					}

					state.editing = updatedFeed;
					$status.text( '\u2713 Saved!' ).addClass( 'success' );
					setTimeout( function () { $status.text( '' ).removeClass( 'success' ); }, 3000 );

				} else {
					var msg = ( res.data && res.data.message ) || 'Save failed.';
					$status.text( '\u2717 ' + msg ).addClass( 'error' );
				}
			}
		).fail( function () {
			$btn.prop( 'disabled', false ).text( 'Save Feed' );
			$status.text( '\u2717 Network error.' ).addClass( 'error' );
		} );
	}

	// ── AJAX loaders ──────────────────────────────────────────────────────────

	/**
	 * Load Google Drive spreadsheets for an account and populate the dropdown.
	 *
	 * @param {string|number} accountId - Account ID.
	 * @param {Function}      [callback] - Called after the dropdown is populated.
	 */
	function loadSpreadsheets( accountId, callback ) {
		var $spinner = $( '#gfgs-ss-spinner' );
		var $select  = $( '#gfgs-spreadsheet-select' );
		$spinner.show();
		$select.prop( 'disabled', true );

		$.post(
			AJAX,
			{ action: 'gfgs_get_spreadsheets', nonce: NONCE, account_id: accountId },
			function ( res ) {
				$spinner.hide();
				if ( res.success ) {
					state.spreadsheets = res.data || [];
					var feed = state.editing;
					var opts = '<option value="">— Select Spreadsheet —</option>';
					state.spreadsheets.forEach( function ( ss ) {
						opts += '<option value="' + esc( ss.id ) + '"' + ( feed.spreadsheet_id == ss.id ? ' selected' : '' ) + '>' + esc( ss.name ) + '</option>';
					} );
					$select.html( opts ).prop( 'disabled', false );
					if ( callback ) { callback(); }
				} else {
					$select.prop( 'disabled', false );
					alert( ( res.data && res.data.message ) || 'Could not load spreadsheets.' );
				}
			}
		);
	}

	/**
	 * Load sheet tabs for a spreadsheet and populate the sheet dropdown.
	 *
	 * @param {string|number} accountId     - Account ID.
	 * @param {string}        spreadsheetId - Spreadsheet file ID.
	 * @param {Function}      [callback]    - Called after the dropdown is populated.
	 */
	function loadSheets( accountId, spreadsheetId, callback ) {
		var $spinner = $( '#gfgs-sh-spinner' );
		var $select  = $( '#gfgs-sheet-select' );
		$spinner.show();
		$select.prop( 'disabled', true );

		$.post(
			AJAX,
			{ action: 'gfgs_get_sheets', nonce: NONCE, account_id: accountId, spreadsheet_id: spreadsheetId },
			function ( res ) {
				$spinner.hide();
				if ( res.success ) {
					state.sheets = res.data || [];
					var feed = state.editing;
					var opts = '<option value="">— Select Sheet Tab —</option>';
					state.sheets.forEach( function ( sh ) {
						opts += '<option value="' + esc( sh.title ) + '"' + ( feed.sheet_name == sh.title ? ' selected' : '' ) + '>' + esc( sh.title ) + '</option>';
					} );
					$select.html( opts ).prop( 'disabled', false );
					if ( callback ) { callback(); }
				} else {
					$select.prop( 'disabled', false );
					alert( ( res.data && res.data.message ) || 'Could not load sheet tabs.' );
				}
			}
		);
	}

	/**
	 * Load the header row of a sheet and rebuild the field-mapper.
	 *
	 * Merges new columns into the existing field_map without removing
	 * previously mapped columns.
	 *
	 * @param {string|number} accountId     - Account ID.
	 * @param {string}        spreadsheetId - Spreadsheet file ID.
	 * @param {string}        sheetName     - Sheet (tab) name.
	 * @param {Function}      [callback]    - Called after the mapper is refreshed.
	 */
	function loadHeaders( accountId, spreadsheetId, sheetName, callback ) {
		var $spinner = $( '#gfgs-hd-spinner' );
		$spinner.show();

		$.post(
			AJAX,
			{ action: 'gfgs_get_sheet_headers', nonce: NONCE, account_id: accountId, spreadsheet_id: spreadsheetId, sheet_name: sheetName },
			function ( res ) {
				$spinner.hide();
				if ( res.success ) {
					state.headers = res.data || [];

					// Merge: keep existing mappings, add unmapped columns.
					var feed         = state.editing;
					var existingCols = ( feed.field_map || [] ).map( function ( m ) { return m.sheet_column || m.column; } );
					state.headers.forEach( function ( h ) {
						if ( existingCols.indexOf( h ) === -1 ) {
							feed.field_map.push( { sheet_column: h, field_id: '', field_type: 'standard' } );
						}
					} );

					refreshFieldMapper();

					// Show the "Add Column" row now that headers are loaded.
					$( '#gfgs-add-field-row' ).show();
					$( '#gfgs-new-column-select' ).html(
						'<option value="">— Add Column —</option>' +
						renderUnmappedColumnOptions( feed.field_map, state.headers )
					);

					if ( callback ) { callback(); }
				} else {
					alert( ( res.data && res.data.message ) || 'Could not load sheet headers.' );
				}
			}
		);
	}

})(jQuery);