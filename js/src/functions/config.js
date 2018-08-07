import { PMA_ajaxShowMessage } from '../utils/show_ajax_messages';
import { PMA_Messages as PMA_messages } from '../variables/export_variables';
import { defaultValues, validate } from '../variables/get_config';
import { validators } from '../classes/Config';
/**
 * checks whether browser supports web storage
 *
 * @param type the type of storage i.e. localStorage or sessionStorage
 *
 * @returns bool
 */
export function isStorageSupported (type, warn) {
    try {
        window[type].setItem('PMATest', 'test');
        // Check whether key-value pair was set successfully
        if (window[type].getItem('PMATest') === 'test') {
            // Supported, remove test variable from storage
            window[type].removeItem('PMATest');
            return true;
        }
    } catch (error) {
        // Not supported
        if (warn) {
            PMA_ajaxShowMessage(PMA_messages.strNoLocalStorage, false);
        }
    }
    return false;
}
/** ******************** Common Functions for Srttings page ****************** */
/**
 * Checks whether field has its default value
 *
 * @param {Element} field
 * @param {String}  type
 * @return boolean
 */
function checkFieldDefault (field, type) {
    var $field = $(field);
    var field_id = $field.attr('id');
    if (typeof defaultValues[field_id] === 'undefined') {
        return true;
    }
    var isDefault = true;
    var currentValue = getFieldValue($field, type);
    if (type !== 'select') {
        isDefault = currentValue === defaultValues[field_id];
    } else {
        // compare arrays, will work for our representation of select values
        if (currentValue.length !== defaultValues[field_id].length) {
            isDefault = false;
        } else {
            for (var i = 0; i < currentValue.length; i++) {
                if (currentValue[i] !== defaultValues[field_id][i]) {
                    isDefault = false;
                    break;
                }
            }
        }
    }
    return isDefault;
}

/**
 * Enables or disables the "restore default value" button
 *
 * @param {Element} field
 * @param {boolean} display
 */
function setRestoreDefaultBtn (field, display) {
    var $el = $(field).closest('td').find('.restore-default img');
    $el[display ? 'show' : 'hide']();
}

/**
 * Marks field depending on its value (system default or custom)
 *
 * @param {Element} field
 */
export function markField (field) {
    var $field = $(field);
    var type = getFieldType($field);
    var isDefault = checkFieldDefault($field, type);

    // checkboxes uses parent <span> for marking
    var $fieldMarker = (type === 'checkbox') ? $field.parent() : $field;
    setRestoreDefaultBtn($field, !isDefault);
    $fieldMarker[isDefault ? 'removeClass' : 'addClass']('custom');
}

/**
 * Sets field value
 *
 * value must be of type:
 * o undefined (omitted) - restore default value (form default, not PMA default)
 * o String - if field_type is 'text'
 * o boolean - if field_type is 'checkbox'
 * o Array of values - if field_type is 'select'
 *
 * @param {Element} field
 * @param {String}  field_type  see {@link #getFieldType}
 * @param {String|Boolean}  value
 */
export function setFieldValue (field, field_type, value) {
    var $field = $(field);
    switch (field_type) {
    case 'text':
    case 'number':
        $field.val(value);
        break;
    case 'checkbox':
        $field.prop('checked', value);
        break;
    case 'select':
        var options = $field.prop('options');
        var i;
        var imax = options.length;
        for (i = 0; i < imax; i++) {
            options[i].selected = (value.indexOf(options[i].value) !== -1);
        }
        break;
    }
    markField($field);
}

/**
 * Returns field type
 *
 * @param {Element} field
 */
export function getFieldType (field) {
    var $field = $(field);
    var tagName = $field.prop('tagName');
    if (tagName === 'INPUT') {
        return $field.attr('type');
    } else if (tagName === 'SELECT') {
        return 'select';
    } else if (tagName === 'TEXTAREA') {
        return 'text';
    }
    return '';
}

/**
 * Gets field value
 *
 * Will return one of:
 * o String - if type is 'text'
 * o boolean - if type is 'checkbox'
 * o Array of values - if type is 'select'
 *
 * @param {Element} field
 * @param {String}  field_type returned by {@link #getFieldType}
 * @type Boolean|String|String[]
 */
function getFieldValue (field, field_type) {
    var $field = $(field);
    switch (field_type) {
    case 'text':
    case 'number':
        return $field.prop('value');
    case 'checkbox':
        return $field.prop('checked');
    case 'select':
        var options = $field.prop('options');
        var i;
        var imax = options.length;
        var items = [];
        for (i = 0; i < imax; i++) {
            if (options[i].selected) {
                items.push(options[i].value);
            }
        }
        return items;
    }
    return null;
}

/**
 * Returns values for all fields in fieldsets
 */
function getAllValues () {
    var $elements = $('fieldset input, fieldset select, fieldset textarea');
    var values = {};
    var type;
    var value;
    for (var i = 0; i < $elements.length; i++) {
        type = getFieldType($elements[i]);
        value = getFieldValue($elements[i], type);
        if (typeof value !== 'undefined') {
            // we only have single selects, fatten array
            if (type === 'select') {
                value = value[0];
            }
            values[$elements[i].name] = value;
        }
    }
    return values;
}

/**
 * Returns element's id prefix
 * @param {Element} element
 */
function getIdPrefix (element) {
    return $(element).attr('id').replace(/[^-]+$/, '');
}

/** ******************** For the {MANAGE YOUR SETTINGS PAGE} ****************** */
/** Contains functions for saving configuration to localstorage */
/**
 * Saves user preferences to localStorage
 *
 * @param {Element} form
 */
export function savePrefsToLocalStorage (form) {
    var $form = $(form);
    var submit = $form.find('input[type=submit]');
    submit.prop('disabled', true);
    $.ajax({
        url: 'prefs_manage.php',
        cache: false,
        type: 'POST',
        data: {
            ajax_request: true,
            server: PMA_commonParams.get('server'),
            submit_get_json: true
        },
        success: function (data) {
            if (typeof data !== 'undefined' && data.success === true) {
                window.localStorage.config = data.prefs;
                window.localStorage.config_mtime = data.mtime;
                window.localStorage.config_mtime_local = (new Date()).toUTCString();
                updatePrefsDate();
                $('div.localStorage-empty').hide();
                $('div.localStorage-exists').show();
                var group = $form.parent('.group');
                group.css('height', group.height() + 'px');
                $form.hide('fast');
                $form.prev('.click-hide-message').show('fast');
            } else {
                PMA_ajaxShowMessage(data.error);
            }
        },
        complete: function () {
            submit.prop('disabled', false);
        }
    });
}

/**
 * Updates preferences timestamp in Import form
 */
export function updatePrefsDate () {
    var d = new Date(window.localStorage.config_mtime_local);
    var msg = PMA_messages.strSavedOn.replace(
        '@DATE@',
        PMA_formatDateTime(d)
    );
    $('#opts_import_local_storage').find('div.localStorage-exists').html(msg);
}

/**
 * Prepares message which informs that localStorage preferences are available and can be imported or deleted
 */
export function offerPrefsAutoimport () {
    var has_config = (isStorageSupported('localStorage')) && (window.localStorage.config || false);
    var $cnt = $('#prefs_autoload');
    if (!$cnt.length || !has_config) {
        return;
    }
    $cnt.find('a').on('click', function (e) {
        e.preventDefault();
        var $a = $(this);
        if ($a.attr('href') === '#no') {
            $cnt.remove();
            $.post('index.php', {
                server: PMA_commonParams.get('server'),
                prefs_autoload: 'hide'
            }, null, 'html');
            return;
        } else if ($a.attr('href') === '#delete') {
            $cnt.remove();
            localStorage.clear();
            $.post('index.php', {
                server: PMA_commonParams.get('server'),
                prefs_autoload: 'hide'
            }, null, 'html');
            return;
        }
        $cnt.find('input[name=json]').val(window.localStorage.config);
        $cnt.find('form').submit();
    });
    $cnt.show();
}

/** ******************** For resetting individual field to default ****************** */
/**
 * Restores field's default value
 *
 * @param {String} field_id
 */
function restoreField (field_id) {
    var $field = $('#' + field_id);
    if ($field.length === 0 || defaultValues[field_id] === undefined) {
        return;
    }
    setFieldValue($field, getFieldType($field), defaultValues[field_id]);
}

export function setupRestoreField () {
    $('div.tabs_contents')
        .on('mouseenter', '.restore-default, .set-value', function () {
            $(this).css('opacity', 1);
        })
        .on('mouseleave', '.restore-default, .set-value', function () {
            $(this).css('opacity', 0.25);
        })
        .on('click', '.restore-default, .set-value', function (e) {
            e.preventDefault();
            var href = $(this).attr('href');
            var field_sel;
            if ($(this).hasClass('restore-default')) {
                field_sel = href;
                restoreField(field_sel.substr(1), defaultValues);
            } else {
                field_sel = href.match(/^[^=]+/)[0];
                var value = href.match(/\=(.+)$/)[1];
                setFieldValue($(field_sel), 'text', value);
            }
            $(field_sel).trigger('change');
        })
        .find('.restore-default, .set-value')
        // inline-block for IE so opacity inheritance works
        .css({ display: 'inline-block', opacity: 0.25 });
}

/** ******************** For Setting up Config tabs ****************** */
/**
 * Sets active tab
 *
 * @param {String} tab_id
 */
export function setTab (tab_id) {
    $('ul.tabs').each(function () {
        var $this = $(this);
        if (!$this.find('li a[href="#' + tab_id + '"]').length) {
            return;
        }
        $this.find('li').removeClass('active').find('a[href="#' + tab_id + '"]').parent().addClass('active');
        $this.parent().find('div.tabs_contents fieldset').hide().filter('#' + tab_id).show();
        var hashValue = 'tab_' + tab_id;
        location.hash = hashValue;
        $this.parent().find('input[name=tab_hash]').val(hashValue);
    });
}


export function setupConfigTabs () {
    var forms = $('form.config-form');
    forms.each(function () {
        var $this = $(this);
        var $tabs = $this.find('ul.tabs');
        if (!$tabs.length) {
            return;
        }
        // add tabs events and activate one tab (the first one or indicated by location hash)
        $tabs.find('li').removeClass('active');
        $tabs.find('a')
            .on('click', function (e) {
                e.preventDefault();
                setTab($(this).attr('href').substr(1));
            })
            .filter(':first')
            .parent()
            .addClass('active');
        $this.find('div.tabs_contents fieldset').hide().filter(':first').show();
    });
}

export function adjustPrefsNotification () {
    var $prefsAutoLoad = $('#prefs_autoload');
    var $tableNameControl = $('#table_name_col_no');
    var $prefsAutoShowing = ($prefsAutoLoad.css('display') !== 'none');

    if ($prefsAutoShowing && $tableNameControl.length) {
        $tableNameControl.css('top', '55px');
    }
}

/** ******************** For Setting up Validations ****************** */
/**
 * Returns validation functions associated with form field
 *
 * @param {String}  field_id     form field id
 * @param {boolean} onKeyUpOnly  see validateField
 * @type Array
 * @return array of [function, parameters to be passed to function]
 */
function getFieldValidators (field_id, onKeyUpOnly) {
    // look for field bound validator
    var name = field_id && field_id.match(/[^-]+$/)[0];
    if (typeof validators._field[name] !== 'undefined') {
        return [[validators._field[name], null]];
    }

    // look for registered validators
    var functions = [];
    if (typeof validate[field_id] !== 'undefined') {
        // validate[field_id]: array of [type, params, onKeyUp]
        for (var i = 0, imax = validate[field_id].length; i < imax; i++) {
            if (onKeyUpOnly && !validate[field_id][i][2]) {
                continue;
            }
            functions.push([validators[validate[field_id][i][0]], validate[field_id][i][1]]);
        }
    }

    return functions;
}

/**
 * Displays errors for given form fields
 *
 * WARNING: created DOM elements must be identical with the ones made by
 * PhpMyAdmin\Config\FormDisplayTemplate::displayInput()!
 *
 * @param {Object} error_list list of errors in the form {field id: error array}
 */
function displayErrors (error_list) {
    var tempIsEmpty = function (item) {
        return item !== '';
    };

    for (var field_id in error_list) {
        var errors = error_list[field_id];
        var $field = $('#' + field_id);
        var isFieldset = $field.attr('tagName') === 'FIELDSET';
        var $errorCnt;
        if (isFieldset) {
            $errorCnt = $field.find('dl.errors');
        } else {
            $errorCnt = $field.siblings('.inline_errors');
        }

        // remove empty errors (used to clear error list)
        errors = $.grep(errors, tempIsEmpty);

        // CSS error class
        if (!isFieldset) {
            // checkboxes uses parent <span> for marking
            var $fieldMarker = ($field.attr('type') === 'checkbox') ? $field.parent() : $field;
            $fieldMarker[errors.length ? 'addClass' : 'removeClass']('field-error');
        }

        if (errors.length) {
            // if error container doesn't exist, create it
            if ($errorCnt.length === 0) {
                if (isFieldset) {
                    $errorCnt = $('<dl class="errors" />');
                    $field.find('table').before($errorCnt);
                } else {
                    $errorCnt = $('<dl class="inline_errors" />');
                    $field.closest('td').append($errorCnt);
                }
            }

            var html = '';
            for (var i = 0, imax = errors.length; i < imax; i++) {
                html += '<dd>' + errors[i] + '</dd>';
            }
            $errorCnt.html(html);
        } else if ($errorCnt !== null) {
            // remove useless error container
            $errorCnt.remove();
        }
    }
}

/**
 * Validates fieldset and puts errors in 'errors' object
 *
 * @param {Element} fieldset
 * @param {boolean} isKeyUp
 * @param {Object}  errors
 */
function validate_fieldset (fieldset, isKeyUp, errors) {
    var $fieldset = $(fieldset);
    if ($fieldset.length && typeof validators._fieldset[$fieldset.attr('id')] !== 'undefined') {
        var fieldset_errors = validators._fieldset[$fieldset.attr('id')].apply($fieldset[0], [isKeyUp]);
        for (var field_id in fieldset_errors) {
            if (typeof errors[field_id] === 'undefined') {
                errors[field_id] = [];
            }
            if (typeof fieldset_errors[field_id] === 'string') {
                fieldset_errors[field_id] = [fieldset_errors[field_id]];
            }
            $.merge(errors[field_id], fieldset_errors[field_id]);
        }
    }
}

/**
 * Validates form field and puts errors in 'errors' object
 *
 * @param {Element} field
 * @param {boolean} isKeyUp
 * @param {Object}  errors
 */
function validate_field (field, isKeyUp, errors) {
    var args;
    var result;
    var $field = $(field);
    var field_id = $field.attr('id');
    errors[field_id] = [];
    var functions = getFieldValidators(field_id, isKeyUp);
    for (var i = 0; i < functions.length; i++) {
        if (typeof functions[i][1] !== 'undefined' && functions[i][1] !== null) {
            args = functions[i][1].slice(0);
        } else {
            args = [];
        }
        args.unshift(isKeyUp);
        result = functions[i][0].apply($field[0], args);
        if (result !== true) {
            if (typeof result === 'string') {
                result = [result];
            }
            $.merge(errors[field_id], result);
        }
    }
}

/**
 * Validates form field and parent fieldset
 *
 * @param {Element} field
 * @param {boolean} isKeyUp
 */
function validate_field_and_fieldset (field, isKeyUp) {
    var $field = $(field);
    var errors = {};
    validate_field($field, isKeyUp, errors);
    validate_fieldset($field.closest('fieldset.optbox'), isKeyUp, errors);
    displayErrors(errors);
}

export function setupValidation () {
    // register validators and mark custom values
    var $elements = $('.optbox input[id], .optbox select[id], .optbox textarea[id]');
    $elements.each(function () {
        markField(this);
        var $el = $(this);
        $el.on('change', function () {
            validate_field_and_fieldset(this, false);
            markField(this);
        });
        var tagName = $el.attr('tagName');
        // text fields can be validated after each change
        if (tagName === 'INPUT' && $el.attr('type') === 'text') {
            $el.on('keyup', function () {
                validate_field_and_fieldset($el, true);
                markField($el);
            });
        }
        // disable textarea spellcheck
        if (tagName === 'TEXTAREA') {
            $el.attr('spellcheck', false);
        }
    });

    // check whether we've refreshed a page and browser remembered modified
    // form values
    var $check_page_refresh = $('#check_page_refresh');
    if ($check_page_refresh.length === 0 || $check_page_refresh.val() === '1') {
        // run all field validators
        var errors = {};
        for (var i = 0; i < $elements.length; i++) {
            validate_field($elements[i], false, errors);
        }
        // run all fieldset validators
        $('fieldset.optbox').each(function () {
            validate_fieldset(this, false, errors);
        });

        displayErrors(errors);
    } else if ($check_page_refresh) {
        $check_page_refresh.val('1');
    }
}