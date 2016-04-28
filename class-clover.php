<?php

/**
 * MongoDB hostname constant
 */
define( 'CLOVER_DB_HOST', '' );

/**
 * MongoDB port constant
 */
define( 'CLOVER_DB_PORT', '' );

/**
 * MongoDB database name
 */
define( 'CLOVER_DB_NAME', '' );

/**
 * MongoDB database user
 */
define( 'CLOVER_DB_USER', '' );

/**
 * MongoDB database password
 */
define( 'CLOVER_DB_PASS', '' );

/**
 * MongoDB database collection
 */
define( 'CLOVER_DB_COLL', '' );

/**
 * The Clover database class
 *
 * Use this class to interface directly with a MongoDB server to read and write metrics
 * and other data. The CLOVER_DB_* constants are defined in wp-config.php. Please do 
 * not alter or change any of those constants. 
 *
 * # Example initialization:
 *     $c = Clover::init();
 *
 * # Example write:
 *     $c->write([ 
 *       [ 'date' => date( 'Y-m-d H:i:s', time() ), 'category' => 'test', 'action' => 12938 ],
 *       [ 'date' => date( 'Y-m-d H:i:s', strtotime( '1 day ago' ) ), 'category' => 'test', 'action' => 3948 ], 
 *     ]);
 *
 * # Example reads:
 *     $results = $c->read( [ 'x' => [ '$gt' => 1 ] ], [ 'projection' => [ '_id' => 0 ], 'sort' => [ 'x' => -1 ] ] );
 *     $results = $c->read( [ 'category' => 'test' ], [] );
 *
 * @todo Handle failures better in both reading and writing
 */
final class Clover {

	/**
	 * Holder property for the singleton instance
	 *
	 * @access public
	 * @static
	 */
	static $instance = null;

	/**
	 * Holder property for the bulkwrite instance
	 *
	 * @access public
	 * @static
	 */
	static $bulkwrite = null;

	/**
	 * Holder property for the MongoDB manager object
	 *
	 * @access protected
	 * @static
	 */
	protected static $db = null;

	/**
	 * Initialize the database connection and get the singleton instance of this class
	 *
	 * @uses \MongoDB\Driver\Manager
	 * @uses CLOVER_DB_HOST
	 * @uses CLOVER_DB_PORT
	 * @uses CLOVER_DB_NAME
	 * @uses CLOVER_DB_USER
	 * @uses CLOVER_DB_PASS
	 * 
	 * @access public
	 * @static
	 *
	 * @return object Singleton instance of Clover
	 */
	static function init() {
		// Create class instance if not created
		if ( null === self::$instance ) {
			self::$instance = new self;

			// Initialize connection to MongoDB server
			self::$db = new MongoDB\Driver\Manager(
				sprintf( 
					'mongodb://%s:%d/%s', 
					CLOVER_DB_HOST, 
					CLOVER_DB_PORT, 
					CLOVER_DB_NAME 
				), array( 
					'username' => CLOVER_DB_USER, 
					'password' => CLOVER_DB_PASS, 
				)
			);
		}

		// Return singleton instance
		return self::$instance;
	}

	/**
	 * Initialize the bulkwrite object that our class uses to write/delete/update database data
	 *
	 * @uses self::$bulkwrite
	 * @uses MongoDB\Driver\BulkWrite
	 *
	 * @return void
	 */
	static function init_bulkwrite() {
		self::$bulkwrite = new MongoDB\Driver\BulkWrite();
	}

	/**
	 * Read data from the MongoDB server
	 *
	 * @param array $filter Query filters
	 * @param array $options Query options
	 * @param string $collection The Mongo collection to read data from
	 *
	 * @uses MongoDB\Driver\Query
	 * @uses self::$db
	 *
	 * @access public
	 * @static
	 *
	 * @return array Result set from database server
	 */
	static function read( $filter, $options = array(), $collection = CLOVER_DB_COLL ) {
		// Setup the instance - it is not pre-initialized
		self::init();

		// Create the query
		$query = new MongoDB\Driver\Query( $filter, $options );

		// Execute the query
		$cursor = self::$db->executeQuery( $collection, $query );

		// Initialize results array
		$results = [];

		// Loop through resulting documents and store in results array
		foreach ( $cursor as $document ) {
			$results[] = $document;
		}

		return $results;
	}

	/**
	 * Write data to the MongoDB server
	 *
	 * @param array $data Array of MongoDB documents to store
	 * @param string $collection The Mongo collection to store the data in
	 *
	 * @uses MongoDB\Driver\BulkWrite
	 * @uses self::$db
	 *
	 * @access public
	 * @static
	 *
	 * @return void
	 */
	static function write( $data, $collection = CLOVER_DB_COLL ) {
		// Setup the instance - it is not pre-initialized
		self::init();
		self::init_bulkwrite();

		// Loop through data array and execute bulkwrite for each
		foreach ( $data as $d ) {
			$criteria = [
				'data_date' => $d['data_date'], 
				'site'      => $d['site'], 
				'platform'  => $d['platform'], 
			];

			// If this document already exists, update instead of writing a new document
			if ( self::read( $criteria ) ) {
				unset( $d['data_date'] );
				unset( $d['site'] );
				unset( $d['platform'] );

				self::$bulkwrite->update( $criteria, [ '$set' => $d ] );			
			} else {
				self::$bulkwrite->insert( $d );
			}
		}

		// @todo: Use this in the executeBulkWrite() call
		$wc = new MongoDB\Driver\WriteConcern( MongoDB\Driver\WriteConcern::MAJORITY, 1000 );

		// Execute the write and return true if successful
		try {
			self::$db->executeBulkWrite( $collection, self::$bulkwrite, $wc );
			return true;
		} catch ( MongoDB\Driver\Exception\BulkWriteException $e ) {
			return false;
		}

		// Default return false
		return false;
	}

	/**
	 * Update documents based on criteria
	 *
	 * @param array $criteria Document search criteria
	 * @param array $data Data to update
	 *
	 * @uses self::init()
	 * @uses self::init_bulkwrite()
	 * @uses MongoDB\Driver\WriteConcern
	 *
	 * @access public
	 * @static
	 *
	 * @return bool True on success, false on failure
	 */
	static function update( $criteria, $data, $collection = CLOVER_DB_COLL ) {
		// Setup the instance - it is not pre-initialized
		self::init();
		self::init_bulkwrite();

		// Add the update to $bulkwrite
		self::$bulkwrite->update( $criteria, [ '$set' => $data ] );
		
		// @todo: Use this in the executeBulkWrite() call
		$wc = new MongoDB\Driver\WriteConcern( MongoDB\Driver\WriteConcern::MAJORITY, 1000 );

		// Execute the write and return true if successful
		try {
			self::$db->executeBulkWrite( $collection, self::$bulkwrite, $wc );
			return true;
		} catch ( MongoDB\Driver\Exception\BulkWriteException $e ) {
			return false;
		}

		// Default return false
		return false;
	}

	/**
	 * Delete a document based on search criteria
	 *
	 * @param array $criteria Criteria to search and delete on
	 * @param string $collection The collection to search
	 *
	 * @uses self::init()
	 * @uses self::init_bulkwrite()
	 * @uses MongoDB\Driver\WriteConcern
	 *
	 * @access public
	 * @static
	 *
	 * @return bool True on success, false on failure
	 */
	static function delete( $criteria, $collection = CLOVER_DB_COLL ) {
		// Setup the instance - it is not pre-initialized
		self::init();
		self::init_bulkwrite();

		// Add the delete to bulkwrite
		self::$bulkwrite->delete( $criteria );

		// @todo: Use this in the executeBulkWrite() call
		$wc = new MongoDB\Driver\WriteConcern( MongoDB\Driver\WriteConcern::MAJORITY, 1000 );

		// Execute the write and return true if successful
		try {
			self::$db->executeBulkWrite( $collection, self::$bulkwrite, $wc );
			return true;
		} catch ( MongoDB\Driver\Exception\BulkWriteException $e ) {
			return false;
		}

		// Default return false
		return false;
	}

}

/**
 * Wrapper for Clover's write() method
 *
 * @param array $data Data to store
 *
 * @uses Clover::write()
 *
 * @return bool True on success, false on failure
 */
function clover_write( $data ) {
	return Clover::write( $data );
}

/**
 * Wrapper for Clover's read() method
 *
 * @param array $filter Query filters
 *
 * @uses Clover::read()
 * 
 * @return array Query results
 */
function clover_read( $criteria ) {
	return Clover::read( $criteria );
}

/**
 * Wrapper for Clover's delete() method
 *
 * @param array $criteria Criteria to search and delete on
 *
 * @uses Clover::delete()
 *
 * @return bool True on success, false on failure
 */
function clover_delete( $criteria ) {
	return Clover::delete( $criteria );
}

/**
 * Wrapper for Clover's update() method
 *
 * @param array $criteria Criteria to search on
 * @param array $data Data to update
 *
 * @uses Clover::update()
 *
 * @return bool True on success, false on failure
 */
function clover_update( $criteria, $data ) {
	return Clover::update( $criteria, $data );
}

// omit