<?php

namespace Appwrite\Extend;

class Exception extends \Exception
{
    /**
     * Error Codes
     *  
     * Naming the error types based on the following convention 
     * <ENTITY>_<ERROR_TYPE>
     * 
     * Appwrite has the follwing entities:
     * - General
     * - Users
     * - Teams
     * - Memberships
     * - Avatars
     * - Storage
     * - Functions
     * - Deployments
     * - Executions
     * - Collections
     * - Documents
     * - Attributes
     * - Indexes
     * - Projects
     * - Webhooks
     * - Keys
     * - Platform
     * - Domain
     */

    /** General */
    const GENERAL_UNKNOWN                   = 'general_unknown';
    const GENERAL_MOCK                      = 'general_mock';
    const GENERAL_ACCESS_FORBIDDEN          = 'general_access_forbidden';
    const GENERAL_UNKNOWN_ORIGIN            = 'general_unknown_origin';
    const GENERAL_SERVICE_DISABLED          = 'general_service_disabled';
    const GENERAL_UNAUTHORIZED_SCOPE        = 'general_unauthorized_scope';
    const GENERAL_RATE_LIMIT_EXCEEDED       = 'general_rate_limit_exceeded';
    const GENERAL_SMTP_DISABLED             = 'general_smtp_disabled';
    const GENERAL_ARGUMENT_INVALID          = 'general_argument_invalid';
    const GENERAL_QUERY_LIMIT_EXCEEDED      = 'general_query_limit_exceeded';
    const GENERAL_QUERY_INVALID             = 'general_query_invalid';
    const GENERAL_ROUTE_NOT_FOUND           = 'general_route_not_found';
    const GENERAL_CURSOR_NOT_FOUND          = 'general_cursor_not_found';
    const GENERAL_SERVER_ERROR              = 'general_server_error';

    /** Users */
    const USER_COUNT_EXCEEDED               = 'user_count_exceeded';
    const USER_JWT_INVALID                  = 'user_jwt_invalid';
    const USER_ALREADY_EXISTS               = 'user_already_exists';
    const USER_BLOCKED                      = 'user_blocked';
    const USER_INVALID_TOKEN                = 'user_invalid_token';
    const USER_PASSWORD_RESET_REQUIRED      = 'user_password_reset_required';
    const USER_EMAIL_NOT_WHITELISTED        = 'user_email_not_whitelisted';
    const USER_IP_NOT_WHITELISTED           = 'user_ip_not_whitelisted';
    const USER_INVALID_CREDENTIALS          = 'user_invalid_credentials';
    const USER_ANONYMOUS_CONSOLE_PROHIBITED = 'user_anonymous_console_prohibited';
    const USER_SESSION_ALREADY_EXISTS       = 'user_session_already_exists';
    const USER_NOT_FOUND                    = 'user_not_found';
    const USER_EMAIL_ALREADY_EXISTS         = 'user_email_already_exists';
    const USER_PASSWORD_MISMATCH            = 'user_password_mismatch';
    const USER_SESSION_NOT_FOUND            = 'user_session_not_found';
    const USER_UNAUTHORIZED                 = 'user_unauthorized';
    const USER_AUTH_METHOD_UNSUPPORTED      = 'user_auth_method_unsupported';

    /** Teams */
    const TEAM_NOT_FOUND                    = 'team_not_found';
    const TEAM_INVITE_ALREADY_EXISTS        = 'team_invite_already_exists';
    const TEAM_INVITE_NOT_FOUND             = 'team_invite_not_found';
    const TEAM_INVALID_SECRET               = 'team_invalid_secret';
    const TEAM_MEMBERSHIP_MISMATCH          = 'team_membership_mismatch';
    const TEAM_INVITE_MISMATCH              = 'team_invite_mismatch'; 

    /** Membership */
    const MEMBERSHIP_NOT_FOUND              = 'membership_not_found';

    /** Avatars */
    const AVATAR_SET_NOT_FOUND              = 'avatar_set_not_found';
    const AVATAR_NOT_FOUND                  = 'avatar_not_found';
    const AVATAR_IMAGE_NOT_FOUND            = 'avatar_image_not_found';
    const AVATAR_REMOTE_URL_FAILED          = 'avatar_remote_url_failed';
    const AVATAR_ICON_NOT_FOUND             = 'avatar_icon_not_found';

    /** Storage */
    const STORAGE_FILE_NOT_FOUND            = 'storage_file_not_found';
    const STORAGE_DEVICE_NOT_FOUND          = 'storage_device_not_found';
    const STORAGE_FILE_EMPTY                = 'storage_file_empty';
    const STORAGE_FILE_TYPE_UNSUPPORTED     = 'storage_file_type_unsupported';
    const STORAGE_INVALID_FILE_SIZE         = 'storage_invalid_file_size';
    const STORAGE_INVALID_FILE              = 'storage_invalid_file';
    const STORAGE_BUCKET_ALREADY_EXISTS     = 'storage_bucket_already_exists';
    const STORAGE_BUCKET_NOT_FOUND          = 'storage_bucket_not_found';
    const STORAGE_INVALID_CONTENT_RANGE     = 'storage_invalid_content_range';
    const STORAGE_INVALID_RANGE             = 'storage_invalid_range';

    /** Functions */
    const FUNCTION_NOT_FOUND                = 'function_not_found';
    const FUNCTION_RUNTIME_UNSUPPORTED      = 'function_runtime_unsupported';

    /** Deployments */
    const DEPLOYMENT_NOT_FOUND              = 'deployment_not_found';

    /** Builds */
    const BUILD_NOT_FOUND                   = 'build_not_found';
    const BUILD_NOT_READY                   = 'build_not_ready';
    const BUILD_IN_PROGRESS                 = 'build_in_progress';

    /** Execution */
    const EXECUTION_NOT_FOUND               = 'execution_not_found';

    /** Collections */
    const COLLECTION_NOT_FOUND              = 'collection_not_found';
    const COLLECTION_ALREADY_EXISTS         = 'collection_already_exists';
    const COLLECTION_LIMIT_EXCEEDED         = 'collection_limit_exceeded';
    
    /** Documents */
    const DOCUMENT_NOT_FOUND                = 'document_not_found';
    const DOCUMENT_INVALID_STRUCTURE        = 'document_invalid_structure';
    const DOCUMENT_MISSING_PAYLOAD          = 'document_missing_payload';
    const DOCUMENT_ALREADY_EXISTS           = 'document_already_exists';

    /** Attribute */
    const ATTRIBUTE_NOT_FOUND               = 'attribute_not_found';
    const ATTRIBUTE_UNKNOWN                 = 'attribute_unknown';
    const ATTRIBUTE_NOT_AVAILABLE           = 'attribute_not_available';
    const ATTRIBUTE_FORMAT_UNSUPPORTED      = 'attribute_format_unsupported';
    const ATTRIBUTE_DEFAULT_UNSUPPORTED     = 'attribute_default_unsupported';
    const ATTRIBUTE_ALREADY_EXISTS          = 'attribute_already_exists';
    const ATTRIBUTE_LIMIT_EXCEEDED          = 'attribute_limit_exceeded';
    const ATTRIBUTE_VALUE_INVALID           = 'attribute_value_invalid';

    /** Indexes */
    const INDEX_NOT_FOUND                   = 'index_not_found';
    const INDEX_LIMIT_EXCEEDED              = 'index_limit_exceeded';
    const INDEX_ALREADY_EXISTS              = 'index_already_exists';

    /** Projects */
    const PROJECT_NOT_FOUND                 = 'project_not_found';
    const PROJECT_UNKNOWN                   = 'project_unknown';
    const PROJECT_PROVIDER_DISABLED         = 'project_provider_disabled';
    const PROJECT_PROVIDER_UNSUPPORTED      = 'project_provider_unsupported';
    const PROJECT_INVALID_SUCCESS_URL       = 'project_invalid_success_url';
    const PROJECT_INVALID_FAILURE_URL       = 'project_invalid_failure_url';
    const PROJECT_MISSING_USER_ID           = 'project_missing_user_id';

    /** Webhooks */
    const WEBHOOK_NOT_FOUND                 = 'webhook_not_found';

    /** Keys */
    const KEY_NOT_FOUND                     = 'key_not_found';

    /** Platform */
    const PLATFORM_NOT_FOUND                = 'platform_not_found';

    /** Domain */
    const DOMAIN_NOT_FOUND                  = 'domain_not_found';
    const DOMAIN_ALREADY_EXISTS             = 'domain_already_exists';
    const DOMAIN_VERIFICATION_FAILED        = 'domain_verification_failed';


    private $type = '';

    public function __construct(string $message, int $code = 0, string $type = Exception::GENERAL_UNKNOWN, \Throwable $previous = null)
    {
        $this->type = $type;

        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the type of the exception.
     * 
     * @return string
     */ 
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set the type of the exception.
     * 
     * @param string $type
     * 
     * @return void
     */
    public function setType(string $type): void
    {
        $this->type = $type;
    }

}