<?php

// These are the relevant capabilities we can use:
// https://codex.wordpress.org/Roles_and_Capabilities
// https://codex.wordpress.org/Function_Reference/map_meta_cap
// edit_users - 2.0
// edit_user (meta)
// delete_users - 2.1
// delete_user (meta)
// remove_users - 3.0 (what's the difference?)
// remove_user (meta)
// create_users - 2.1
// list_users - 3.0
// add_users - 3.0
// promote_users - 3.0 (this is about changing a users's level... not sure it's relevant to roles/caps)
// promote_user (meta)

class WP_JSON_Users {
	/**
	 * Server object
	 *
	 * @var WP_JSON_ResponseHandler
	 */
	protected $server;

	/**
	 * Constructor
	 *
	 * @param WP_JSON_ResponseHandler $server Server object
	 */
	public function __construct( WP_JSON_ResponseHandler $server ) {
		$this->server = $server;
	}

	/**
	 * Register the user-related routes
	 *
	 * @param array $routes Existing routes
	 * @return array Modified routes
	 */
	public function register_routes( $routes ) {
		$user_routes = array(
			// User endpoints
			'/users' => array(
				array( array( $this, 'get_users' ), WP_JSON_Server::READABLE ),
				array( array( $this, 'new_user' ), WP_JSON_Server::CREATABLE | WP_JSON_Server::ACCEPT_JSON ),
			),
			'/users/(?P<id>\d+)' => array(
				array( array( $this, 'get_user' ), WP_JSON_Server::READABLE ),
				array( array( $this, 'edit_user' ), WP_JSON_Server::EDITABLE | WP_JSON_Server::ACCEPT_JSON ),
				array( array( $this, 'delete_user' ), WP_JSON_Server::DELETABLE ),
			)
		);
		return array_merge( $routes, $user_routes );
	}

	/**
	 * Retrieve users.
	 *
	 * @param array $filter Extra query parameters for {@see WP_User_Query}
	 * @param string $context optional
	 * @param int $page Page number (1-indexed)
	 * @return array contains a collection of User entities.
	 */
	public function get_users( $filter = array(), $context = 'view', $page = 1 ) {

		if ( ! current_user_can( 'list_users' ) ) {
			return new WP_Error( 'json_user_cannot_list', __( 'Sorry, you are not allowed to list users.' ), array( 'status' => 403 ) );
		}

		$args = array(
			'orderby' => 'user_login',
			'order' => 'ASC'
		);
		$args = array_merge( $args, $filter );
		$args = apply_filters( 'json_user_query', $args, $filter, $context, $page );

		// Pagination
		$args['number'] = empty( $args['number'] ) ? 10 : absint( $args['number'] );
		$page = absint( $page );
		$args['offset'] = ( $page - 1 ) * $args['number'];

		$user_query = new WP_User_Query( $args );
		if ( empty( $user_query->results ) ) {
			return array();
		}

		$struct = array();
		foreach ( $user_query->results as $user ) {
			$struct[] = $this->prepare_user( $user, $context );
		}

		return $struct;
	}

	/**
	 * Retrieve a user.
	 *
	 * @param int $id User ID
	 * @param string $context
	 * @return response
	 */
	public function get_user( $id, $context = 'view' ) {
		$current_user_id = get_current_user_id();
		if ( $current_user_id !== $id && ! current_user_can( 'list_users' ) ) {
			return new WP_Error( 'json_user_cannot_list', __( 'Sorry, you are not allowed to view this user.' ), array( 'status' => 403 ) );
		}

		$user = get_userdata( $id );

		if ( empty( $user->ID ) ) {
			return new WP_Error( 'json_user_invalid_id', __( 'Invalid user ID.' ), array( 'status' => 400 ) );
		}

		return $this->prepare_user( $user, $context );
	}

	/**
	 *
	 * Prepare a User entity from a WP_User instance.
	 *
	 * @param WP_User $user
	 * @param string $context
	 * @return array
	 */
	protected function prepare_user( $user, $context = 'view' ) {
		$user_fields = array(
			'ID' => $user->ID,
			'username' => $user->user_login,
			'name' => $user->display_name,
			'first_name' => $user->first_name,
			'last_name' => $user->last_name,
			'nickname' => $user->nickname,
			'slug' => $user->user_nicename,
			'URL' => $user->user_url,
			'avatar' => $this->server->get_avatar_url( $user->user_email ),
			'description' => $user->description,
			'email' => $user->user_email,
			'registered' => $user->user_registered,
			'roles' => $user->roles,
			'capabilities' => $user->allcaps,
		);

		if ( $context === 'edit' ) {
			// The user's specific caps should only be needed if you're editing
			// the user, as allcaps should handle most users
			$user_fields['extra_capabilities'] = $user->caps;
		}

		$user_fields['meta'] = array(
			'links' => array(
				'self' => json_url( '/users/' . $user->ID ),
				'archives' => json_url( '/users/' . $user->ID . '/posts' ),
			),
		);
		return apply_filters( 'json_prepare_user', $user_fields, $user, $context );
	}

	protected function insert_user( $data ) {
		if ( ! empty( $data['ID'] ) ) {
			$user = get_userdata( $data['ID'] );
			if ( ! $user ) {
				return new WP_Error( 'json_user_invalid_id', __( 'Invalid user ID.' ), array( 'status' => 404 ) );
			}

			if ( ! current_user_can( 'edit_user', $data['ID'] ) ) {
				return new WP_Error( 'json_user_cannot_edit', __( 'Sorry, you are not allowed to edit this user.' ), array( 'status' => 403 ) );
			}

			$required = array( 'username', 'password', 'email' );
			foreach ( $required as $arg ) {
				if ( empty( $data[ $arg ] ) ) {
					return new WP_Error( 'json_missing_callback_param', sprintf( __( 'Missing parameter %s' ), $arg ), array( 'status' => 400 ) );
				}
			}

			$update = true;
		}
		else {
			$user = new WP_User();

			if ( ! current_user_can( 'create_users' ) ) {
				return new WP_Error( 'json_cannot_create', __( 'Sorry, you are not allowed to create users.' ), array( 'status' => 403 ) );
			}

			$update = false;
		}

		// Basic authentication details
		if ( isset( $data['username'] ) ) {
			$user->user_login = $data['username'];
		}
		if ( isset( $data['password'] ) ) {
			$user->user_pass = $data['password'];
		}

		// Names
		if ( isset( $data['name'] ) ) {
			$user->display_name = $data['name'];
		}
		if ( isset( $data['first_name'] ) ) {
			$user->first_name = $data['first_name'];
		}
		if ( isset( $data['last_name'] ) ) {
			$user->last_name = $data['last_name'];
		}
		if ( isset( $data['nickname'] ) ) {
			$user->nickname = $data['nickname'];
		}
		if ( ! empty( $data['slug'] ) ) {
			$user->user_nicename = $data['slug'];
		}

		// URL
		if ( ! empty( $data['URL'] ) ) {
			$escaped = esc_url_raw( $user->user_url );
			if ( $escaped !== $user->user_url ) {
				return new WP_Error( 'json_invalid_url', __( 'Invalid user URL.' ), array( 'status' => 400 ) );
			}

			$user->user_url = $data['URL'];
		}

		// Description
		if ( ! empty( $data['description'] ) ) {
			$user->description = $data['description'];
		}

		// Email
		if ( ! empty( $data['email'] ) ) {
			$user->user_email = $data['email'];
		}

		// Pre-flight check
		$user = apply_filters( 'json_pre_insert_user', $user, $data );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$user_id = $update ? wp_update_user( $user ) : wp_insert_user( $user );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user->ID = $user_id;

		do_action( 'json_insert_user', $user, $data, $update );

		return $user_id;
	}

	/**
	 * Edit a user.
	 *
	 * The $data parameter only needs to contain fields that should be changed.
	 * All other fields will retain their existing values.
	 *
	 * @param int $id User ID to edit
	 * @param array $data Data construct
	 * @param array $_headers Header data
	 * @return true on success
	 */
	public function edit_user( $id, $data, $_headers = array() ) {
		$id = absint( $id );

		if ( empty( $id ) ) {
			return new WP_Error( 'json_user_invalid_id', __( 'User ID must be supplied.' ), array( 'status' => 400 ) );
		}

		// Permissions check
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error( 'json_user_cannot_edit', __( 'Sorry, you are not allowed to edit this user.' ), array( 'status' => 403 ) );
		}

		$user = get_userdata( $id );
		if ( ! $user ) {
			return new WP_Error( 'json_user_invalid_id', __( 'User ID is invalid.' ), array( 'status' => 400 ) );
		}

		$data['ID'] = $user->ID;

		// Update attributes of the user from $data
		$retval = $this->insert_user( $data );
		if ( is_wp_error( $retval ) ) {
			return $retval;
		}

		return $this->get_user( $id );
	}

	/**
	 * Create a new user.
	 *
	 * @param $data
	 * @return mixed
	 */
	public function new_user( $data ) {
		if ( ! current_user_can( 'create_users' ) ) {
			return new WP_Error( 'json_cannot_create', __( 'Sorry, you are not allowed to create users.' ), array( 'status' => 403 ) );
		}

		if ( ! empty( $data['ID'] ) ) {
			return new WP_Error( 'json_user_exists', __( 'Cannot create existing user.' ), array( 'status' => 400 ) );
		}

		$user_id = $this->insert_user( $data );
		// TODO: Send appropriate HTTP error codes along with the JSON rendering of the WP_Error we send back
		// TODO: I guess we can just add/overwrite the 'status' code in there ourselves... nested WP_Error?
		// These are the errors wp_insert_user() might return (from the wp_create_user documentation)
		// - empty_user_login, Cannot create a user with an empty login name. => BAD_REQUEST
		// - existing_user_login, This username is already registered. => CONFLICT
		// - existing_user_email, This email address is already registered. => CONFLICT
		// http://stackoverflow.com/questions/942951/rest-api-error-return-good-practices
		// http://stackoverflow.com/questions/3825990/http-response-code-for-post-when-resource-already-exists
		// http://soabits.blogspot.com/2013/05/error-handling-considerations-and-best.html
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$response = $this->get_user( $user_id );
		$response->set_status( 201 );
		$response->header( 'Location', json_url( '/users/' . $user_id ) );
		return $response;
	}

	/**
	 * Delete a user.
	 *
	 * @param int $id
	 * @param bool force
	 * @return true on success
	 */
	public function delete_user( $id, $force = false ) {
		$id = absint( $id );

		if ( empty( $id ) ) {
			return new WP_Error( 'json_user_invalid_id', __( 'Invalid user ID.' ), array( 'status' => 400 ) );
		}

		// Permissions check
		if ( ! current_user_can( 'delete_user', $id ) ) {
			return new WP_Error( 'json_user_cannot_delete', __( 'Sorry, you are not allowed to delete this user.' ), array( 'status' => 403 ) );
		}

		$user = get_userdata( $id );

		if ( ! $user ) {
			return new WP_Error( 'json_user_invalid_id', __( 'Invalid user ID.' ), array( 'status' => 400 ) );
		}

		// https://codex.wordpress.org/Function_Reference/wp_delete_user
		// TODO: Allow posts to be reassigned (see the docs for wp_delete_user) - use a HTTP parameter?
		$result = wp_delete_user( $id );

		if ( ! $result ) {
			return new WP_Error( 'json_cannot_delete', __( 'The user cannot be deleted.' ), array( 'status' => 500 ) );
		}
		else {
			return array( 'message' => __( 'Deleted user' ) );
		}
	}
}
