<?php
// languages/en.php

return [
    // Main interface
    'title' => 'FFE Extension for Equipe',
    'loading' => 'Checking existing imports...',

    // Navigation
    'nav_view_imports' => 'View imports',
    'nav_new_import' => 'New import',

    // Messages
    'no_imports' => 'No FFE events have been imported into this meeting yet.',
    'import_file' => 'Import FFE XML file',
    'select_file' => 'FFE XML File:',
    'select_file_help' => 'Select the XML file exported from FFE',
    'analyze_file' => 'Analyze file',
    'analyze_in_progress' => 'Analyzing file...',

    // Competition table
    'imported_competitions' => 'Imported FFE events',
    'imported_comp_multi' => "imported FFE event(s)",
    'col_event_num' => 'Event No.',
    'col_name' => 'Name',
    'col_date' => 'Date',
    'col_ffe_num' => 'FFE No.',
    'col_results' => 'Results',
    'col_exports' => 'Exports',
    'badge_title' => 'FFE competition number',
    'badge_wait' => 'Pending',

    // Status
    'status_yes' => 'YES',
    'status_waiting' => 'Pending',
    'status_in_progress' => 'Waiting for results',

    // Buttons
    'btn_export_all' => 'Export all to FFECompet',
    'btn_refresh' => 'Refresh',
    'refresh_in_progress' => 'Refreshing...',
    'btn_select_all' => 'Select all',
    'btn_deselect_all' => 'Deselect all',
    'btn_import' => 'Import to Equipe',
    'btn_back' => 'Back',

    // Selection step
    'competition_data' => 'Competition data',
    'select_competitions' => 'Select events to import:',
    'competition_level' => 'Event level:',
    'level_help' => 'This level will be applied to all selected events',

    // Levels
    'level_club' => 'Club',
    'level_local' => 'Local',
    'level_regional' => 'Regional',
    'level_national' => 'National',
    'level_elite' => 'Elite',
    'level_international' => 'International',

    // Statistics
    'stats_competitions' => 'Events',
    'stats_riders' => 'Riders',
    'stats_officials' => 'Officials',
    'stats_horses' => 'Horses',
    'stats_clubs' => 'Clubs',
    'stats_entries' => 'Entries',
    'from' => 'From',
    'to' => 'to',
    'organizer' => "Organizer",

    // Import progress
    'import_in_progress' => 'Import in progress...',
    'import_preparing' => 'Preparing import...',
    'import_success' => 'Import completed successfully!',
    'import_check' => 'Check imports',

    // Error messages
    'error_connection' => 'Connection error: {error}',
    'error_connection_no_param' => 'Connection error',
    'error_no_file' => 'No file selected',
    'error_invalid_xml' => 'Invalid XML file',
    'error_no_selection' => 'Please select at least one event',
    'warning_error' => 'Error: {error}',
    "error_server" => "Server connection error",
    'error_comp_not_found' => "No FFE events found.",
    'error_ffecompet' => "FFECompet export error: {error}",
    'error_in_export' => 'Export error: {error}',
    'error_in_import' => 'Import error: {error}',
    'error_missing_api' => 'Missing configuration. API Key or URL not provided by Equipe.',
    'error_in_analyze' => 'Analysis error: {error}',
    'error' => 'Error: {error}',

    // Success messages
    'success_file_analyzed' => 'File analyzed successfully',
    'success_import_complete' => 'Import complete! Data has been sent to Equipe.',
    'success_export' => 'Export successful',
    'success_global_export' => 'FFECompet global export successful',

    // Tooltips
    'tooltip_export_sif' => 'Export to SIF format',
    'tooltip_export_ffe' => 'Export to FFECompet TXT format',
    'tooltip_global_export_ffe' => 'Export all to FFECompet format',

    // Export
    'export_title' => 'Export results',
    'export_in_progress' => 'Exporting results...',
    'export_completed' => 'Results exported successfully',
    'export_running' => 'Export in progress...',

    // Footer
    'format_info' => 'FFE based on FFECompet V24 text format',
    'generated_on' => 'Generated on'
];
