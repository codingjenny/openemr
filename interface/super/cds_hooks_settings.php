<?php
/**
 * CDS Hooks Settings Management
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @author  CDS Hooks Integration
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/options.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$alertmsg = '';
$error = '';

// Handle form submission
if ($_POST['form_action'] ?? false) {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    
    if ($_POST['form_action'] === 'save_settings') {
        // Save CDS Hook settings
        $enable_cds_hooks = $_POST['enable_cds_hooks'] ? 1 : 0;
        $cds_timeout = intval($_POST['cds_timeout']) ?: 5;
        $cds_debug = $_POST['cds_debug'] ? 1 : 0;
        $cds_discovery_url = trim($_POST['cds_discovery_url']) ?: 'https://sandbox-services.cds-hooks.org/cds-services';
        
        // Update global settings
        sqlStatement("INSERT INTO `globals` (`gl_name`, `gl_value`) VALUES ('enable_cds_hooks', ?) ON DUPLICATE KEY UPDATE `gl_value` = ?", [$enable_cds_hooks, $enable_cds_hooks]);
        sqlStatement("INSERT INTO `globals` (`gl_name`, `gl_value`) VALUES ('cds_hooks_timeout', ?) ON DUPLICATE KEY UPDATE `gl_value` = ?", [$cds_timeout, $cds_timeout]);
        sqlStatement("INSERT INTO `globals` (`gl_name`, `gl_value`) VALUES ('cds_hooks_debug', ?) ON DUPLICATE KEY UPDATE `gl_value` = ?", [$cds_debug, $cds_debug]);
        sqlStatement("INSERT INTO `globals` (`gl_name`, `gl_value`) VALUES ('cds_hooks_discovery_url', ?) ON DUPLICATE KEY UPDATE `gl_value` = ?", [$cds_discovery_url, $cds_discovery_url]);
        
        $alertmsg = xlt("CDS Hook settings saved successfully.");
    }
}

// Get current settings
$enable_cds_hooks = getGlobalSetting('enable_cds_hooks') ?: 0;
$cds_timeout = getGlobalSetting('cds_hooks_timeout') ?: 5;
$cds_debug = getGlobalSetting('cds_hooks_debug') ?: 0;
$cds_discovery_url = getGlobalSetting('cds_hooks_discovery_url') ?: 'https://sandbox-services.cds-hooks.org/cds-services';

function getGlobalSetting($settingKey) 
{
    return $GLOBALS[$settingKey] ?? null;
}

?>

<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(['common', 'datetime-picker']); ?>
    <title><?php echo xlt('CDS Hooks Configuration'); ?></title>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-cogs"></i> <?php echo xlt('CDS Hooks Configuration'); ?>
                        </h5>
                        <small class="text-muted"><?php echo xlt('Configure Clinical Decision Support Hooks services and discovery settings'); ?></small>
                    </div>
                    <div class="card-body">
                        <?php if ($alertmsg) : ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?php echo text($alertmsg); ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <?php if ($error) : ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?php echo text($error); ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="">
                            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                            <input type="hidden" name="form_action" value="save_settings" />

                            <div class="form-group">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="enable_cds_hooks" id="enable_cds_hooks" 
                                           <?php echo $enable_cds_hooks ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="enable_cds_hooks">
                                        <?php echo xlt('Enable CDS Hooks Integration'); ?>
                                    </label>
                                </div>
                                <small class="form-text text-muted">
                                    <?php echo xlt('Automatically trigger CDS Hook services when viewing patient data'); ?>
                                </small>
                            </div>

                            <div class="form-group">
                                <label for="cds_timeout"><?php echo xlt('CDS Service Timeout (seconds)'); ?></label>
                                <input type="number" class="form-control" name="cds_timeout" id="cds_timeout" 
                                       value="<?php echo attr($cds_timeout); ?>" min="1" max="30">
                                <small class="form-text text-muted">
                                    <?php echo xlt('Maximum time to wait for CDS service response'); ?>
                                </small>
                            </div>

                            <div class="form-group">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="cds_debug" id="cds_debug" 
                                           <?php echo $cds_debug ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="cds_debug">
                                        <?php echo xlt('Enable Debug Mode'); ?>
                                    </label>
                                </div>
                                <small class="form-text text-muted">
                                    <?php echo xlt('Log detailed CDS Hook requests and responses'); ?>
                                </small>
                            </div>

                            <div class="form-group">
                                <label for="cds_discovery_url"><?php echo xlt('CDS Hooks Discovery URL'); ?></label>
                                <input type="url" class="form-control" name="cds_discovery_url" id="cds_discovery_url" 
                                       value="<?php echo attr($cds_discovery_url); ?>" placeholder="https://example.com/cds-services">
                                <small class="form-text text-muted">
                                    <?php echo xlt('URL endpoint for CDS Hooks service discovery. This URL should return available CDS services in the standard discovery format.'); ?>
                                </small>
                            </div>

                            <div class="form-group">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6><?php echo xlt('Available CDS Services'); ?></h6>
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="refresh-services">
                                        <i class="fas fa-sync"></i> <?php echo xlt('Discover Services'); ?>
                                    </button>
                                </div>
                                
                                <div id="services-loading" class="text-center py-3" style="display: none;">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="sr-only"><?php echo xlt('Loading...'); ?></span>
                                    </div>
                                    <p class="mt-2"><?php echo xlt('Discovering CDS services...'); ?></p>
                                </div>
                                
                                <div id="services-error" class="alert alert-danger" style="display: none;"></div>
                                
                                <div class="table-responsive">
                                    <table class="table table-sm" id="services-table">
                                        <thead>
                                            <tr>
                                                <th><?php echo xlt('Enable'); ?></th>
                                                <th><?php echo xlt('Service'); ?></th>
                                                <th><?php echo xlt('ID'); ?></th>
                                                <th><?php echo xlt('Hook Type'); ?></th>
                                                <th><?php echo xlt('Description'); ?></th>
                                                <th><?php echo xlt('Status'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="services-list">
                                            <tr>
                                                <td colspan="6" class="text-center text-muted">
                                                    <?php echo xlt('Click "Discover Services" to load available CDS services'); ?>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <small class="form-text text-muted">
                                    <?php echo xlt('Services will be discovered from the Discovery URL above. Toggle individual services on/off as needed.'); ?>
                                </small>
                            </div>

                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> <?php echo xlt('Save Settings'); ?>
                                </button>
                                <a href="edit_globals.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> <?php echo xlt('Back to Configuration'); ?>
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
$(document).ready(function() {
    let csrfToken = '';
    
    // Get CSRF token on page load
    function getCsrfToken() {
        $.get('cds_services_api.php?action=get_csrf_token')
            .done(function(response) {
                if (response.success) {
                    csrfToken = response.csrf_token;
                }
            });
    }
    
    // Initialize
    getCsrfToken();
    
    // Refresh/Discover Services
    $('#refresh-services').click(function() {
        discoverServices();
    });
    
    // Auto-discover services when discovery URL changes
    $('#cds_discovery_url').on('blur', function() {
        if ($(this).val().trim()) {
            discoverServices();
        }
    });
    
    function discoverServices() {
        $('#services-loading').show();
        $('#services-error').hide();
        $('#refresh-services').prop('disabled', true);
        
        $.get('cds_services_api.php?action=discover')
            .done(function(response) {
                if (response.success) {
                    displayServices(response.services);
                } else {
                    showError(response.error || '<?php echo xlt("Failed to discover services"); ?>');
                }
            })
            .fail(function(xhr) {
                let errorMsg = '<?php echo xlt("Network error occurred while discovering services"); ?>';
                if (xhr.responseJSON && xhr.responseJSON.error) {
                    errorMsg = xhr.responseJSON.error;
                }
                showError(errorMsg);
            })
            .always(function() {
                $('#services-loading').hide();
                $('#refresh-services').prop('disabled', false);
            });
    }
    
    function displayServices(services) {
        const tbody = $('#services-list');
        tbody.empty();
        
        if (!services || services.length === 0) {
            tbody.html('<tr><td colspan="6" class="text-center text-muted"><?php echo xlt("No CDS services found"); ?></td></tr>');
            return;
        }
        
        services.forEach(function(service) {
            const hookTypes = Array.isArray(service.hook) ? service.hook.join(', ') : (service.hook || 'Unknown');
            const description = service.description || service.title || 'No description available';
            const serviceId = service.id || '';
            const title = service.title || serviceId;
            
            const row = $(`
                <tr data-service-id="${escapeHtml(serviceId)}">
                    <td>
                        <div class="form-check form-switch">
                            <input class="form-check-input service-toggle" type="checkbox" 
                                   ${service.enabled ? 'checked' : ''} 
                                   data-service-id="${escapeHtml(serviceId)}"
                                   id="service_${escapeHtml(serviceId)}">
                        </div>
                    </td>
                    <td><strong>${escapeHtml(title)}</strong></td>
                    <td><code>${escapeHtml(serviceId)}</code></td>
                    <td>
                        <span class="badge badge-info">${escapeHtml(hookTypes)}</span>
                    </td>
                    <td class="small">${escapeHtml(description)}</td>
                    <td>
                        <span class="badge ${service.enabled ? 'badge-success' : 'badge-secondary'}">
                            ${service.enabled ? '<?php echo xlt("Enabled"); ?>' : '<?php echo xlt("Disabled"); ?>'}
                        </span>
                    </td>
                </tr>
            `);
            
            tbody.append(row);
        });
        
        // Add event handlers for toggle switches
        $('.service-toggle').change(function() {
            toggleService($(this));
        });
    }
    
    function toggleService($toggle) {
        const serviceId = $toggle.data('service-id');
        const enabled = $toggle.is(':checked');
        const $row = $toggle.closest('tr');
        const $statusBadge = $row.find('.badge');
        
        // Disable toggle while processing
        $toggle.prop('disabled', true);
        
        $.post('cds_services_api.php', {
            action: 'toggle_service',
            service_id: serviceId,
            enabled: enabled.toString(),
            csrf_token: csrfToken
        })
        .done(function(response) {
            if (response.success) {
                // Update status badge
                if (enabled) {
                    $statusBadge.removeClass('badge-secondary').addClass('badge-success')
                               .text('<?php echo xlt("Enabled"); ?>');
                } else {
                    $statusBadge.removeClass('badge-success').addClass('badge-secondary')
                               .text('<?php echo xlt("Disabled"); ?>');
                }
                
                // Show success message
                showSuccess('<?php echo xlt("Service status updated successfully"); ?>');
            } else {
                // Revert toggle on error
                $toggle.prop('checked', !enabled);
                showError(response.error || '<?php echo xlt("Failed to update service status"); ?>');
            }
        })
        .fail(function(xhr) {
            // Revert toggle on error
            $toggle.prop('checked', !enabled);
            let errorMsg = '<?php echo xlt("Network error occurred"); ?>';
            if (xhr.responseJSON && xhr.responseJSON.error) {
                errorMsg = xhr.responseJSON.error;
            }
            showError(errorMsg);
        })
        .always(function() {
            $toggle.prop('disabled', false);
        });
    }
    
    function showError(message) {
        $('#services-error').text(message).show();
        setTimeout(function() {
            $('#services-error').fadeOut();
        }, 5000);
    }
    
    function showSuccess(message) {
        // Create a temporary success alert
        const alert = $(`
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                ${escapeHtml(message)}
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        `);
        
        $('.card-body').prepend(alert);
        
        setTimeout(function() {
            alert.fadeOut(function() {
                $(this).remove();
            });
        }, 3000);
    }
    
    function escapeHtml(text) {
        return $('<div>').text(text).html();
    }
    
    // Auto-discover services on page load if discovery URL is set
    if ($('#cds_discovery_url').val().trim()) {
        setTimeout(discoverServices, 500);
    }
});
</script>
</body>
</html>
