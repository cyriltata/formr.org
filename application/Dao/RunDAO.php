<?php

class RunDAO {

	/**
	 *
	 * @var Request 
	 */
	protected $request;

	/**
	 *
	 * @var string
	 */
	protected $run_name;

	/**
	 * @var Run
	 */
	protected $run;

	/**
	 *
	 * @var RunSession
	 */
	protected $runSession;

	/**
	 *
	 * @var DB
	 */
	protected $db;

	protected $errors = array();
	protected $message = null;

	public function __construct(Request $r, DB $db, $run) {
		$this->request = $r;
		$this->run_name = $run;
		$this->db = $db;
		$this->run = new Run($this->db, $run);

		if (!$this->run->valid) {
			throw new Exception("Run with name {$run} not found");
		}

		if ($this->request->session) {
			$this->runSession = new RunSession($this->db, $this->run->id, null, $this->request->session, $this->run);
		}
	}

	public function sendToPosition() {
		if (!$this->request->session || !$this->request->new_position) {
			$this->errors[] = 'Missing session or position parameter';
			return false;
		}

		$run_session = $this->runSession;
		if (!$run_session->forceTo($this->request->new_position)) {
			$this->errors[] = 'Something went wrong with the position change in run ' . $this->run->name;
			return false;
		}

		$this->message = 'Run-session successfully set to position ' . $this->request->new_position;
		return true;
	}

	public function remind() {
		$email = $this->run->getReminder($this->request->session, $this->request->run_session_id);
		if ($email->exec() !== false) {
			$this->errors[] = 'Something went wrong with the reminder. in run ' . $this->run->name;
			return false;
		}
		$this->message = 'Reminder sent';
		return true;
	}

	public function nextInRun() {
		if (!$this->runSession->runToNextUnit()) {
			$this->errors[] = 'Unable to move to next unit in run ' . $this->run->name;
			return false;
		}
		$this->message = 'Move done';
		return true;
	}

	public function deleteUser() {
		$session = $this->request->session;
		if (($deleted = $this->db->delete('survey_run_sessions', array('id' => $this->request->run_session_id)))) {
			$this->message = "User with session '{$session}' was deleted";
		} else {
			$this->errors[] = "User with session '{$session}' could not be deleted";
		}
	}

	public function getRunSession() {
		return $this->runSession;
	}

	public function getErrors() {
		return $this->errors;
	}

	public function getMessage() {
		return $this->message;
	}
}
