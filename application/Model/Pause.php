<?php

class Pause extends RunUnit {

	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	protected $body = '';
	protected $body_parsed = '';
	protected $relative_to = null;
	protected $wait_minutes = null;
	protected $wait_until_time = null;
	protected $wait_until_date = null;
	public $ended = false;
	public $type = "Pause";
	public $icon = "fa-pause";
	
	/**
	 * An array of unit's exportable attributes
	 * @var array
	 */
	public $export_attribs = array('type', 'description', 'position', 'special', 'wait_until_time', 'wait_until_date', 'wait_minutes', 'relative_to', 'body');

	public function __construct($fdb, $session = null, $unit = null, $run_session = NULL, $run = NULL) {
		parent::__construct($fdb, $session, $unit, $run_session, $run);

		if ($this->id):
			$vars = $this->dbh->select('id, body, body_parsed, wait_until_time, wait_minutes, wait_until_date, relative_to')
							->from('survey_pauses')
							->where(array('id' => $this->id))
							->limit(1)->fetch();

			if ($vars):
				array_walk($vars, "emptyNull");
				$this->body = $vars['body'];
				$this->body_parsed = $vars['body_parsed'];
				$this->wait_until_time = $vars['wait_until_time'];
				$this->wait_until_date = $vars['wait_until_date'];
				$this->wait_minutes = $vars['wait_minutes'];
				$this->relative_to = $vars['relative_to'];

				$this->valid = true;
			endif;
		endif;
	}

	public function create($options) {
		$this->dbh->beginTransaction();
		if (!$this->id) {
			$this->id = parent::create($this->type);
		} else {
			$this->modify($options);
		}

		if (isset($options['body'])) {
			array_walk($options, "emptyNull");
			$this->body = $options['body'];
			$this->wait_until_time = $options['wait_until_time'];
			$this->wait_until_date = $options['wait_until_date'];
			$this->wait_minutes = $options['wait_minutes'];
			$this->relative_to = $options['relative_to'];
		}

		$parsedown = new ParsedownExtra();
		$parsedown->setBreaksEnabled(true);
		$this->body_parsed = $parsedown->text($this->body); // transform upon insertion into db instead of at runtime

		$this->dbh->insert_update('survey_pauses', array(
			'id' => $this->id,
			'body' => $this->body,
			'body_parsed' => $this->body_parsed,
			'wait_until_time' => $this->wait_until_time,
			'wait_until_date' => $this->wait_until_date,
			'wait_minutes' => $this->wait_minutes,
			'relative_to' => $this->relative_to,
		));
		$this->dbh->commit();
		$this->valid = true;

		return true;
	}

	public function displayForRun($prepend = '') {
		$dialog = '<p>
				
				<label class="inline hastooltip" title="Leave empty so that this does not apply">wait until time: 
				<input style="width:200px" class="form-control" type="time" placeholder="e.g. 12:00" name="wait_until_time" value="' . h($this->wait_until_time) . '">
				</label> <strong>and</strong>
				
				</p>
				<p>
				<label class="inline hastooltip" title="Leave empty so that this does not apply">wait until date: 
				<input style="width:200px" class="form-control" type="date" placeholder="e.g. 01.01.2000" name="wait_until_date" value="' . h($this->wait_until_date) . '">
				</label> <strong>and</strong>
				
				</p>
				<p class="well well-sm">
					<span class="input-group">
						<input class="form-control" type="number" style="width:230px" placeholder="wait this many minutes" name="wait_minutes" value="' . h($this->wait_minutes) . '">
				        <span class="input-group-btn">
							<button class="btn btn-default from_days hastooltip" title="Enter a number of days and press this button to convert them to minutes (*60*24)"><small>convert days</small></button>
						</span>
					</span>
					
				 <label class="inline">relative to 
					<textarea data-editor="r" style="width:368px;" rows="2" class="form-control" placeholder="arriving at this pause" name="relative_to">' . h($this->relative_to) . '</textarea>
					</label
				</p> 
		<p><label>Text to show while waiting: <br>
			<textarea style="width:388px;"  data-editor="markdown" class="form-control col-md-5" placeholder="You can use Markdown" name="body" rows="10">' . h($this->body) . '</textarea>
		</label></p>
			';
		$dialog .= '<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Pause">Save</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=Pause">Test</a></p>';


		$dialog = $prepend . $dialog;

		return parent::runDialog($dialog, 'fa-pause');
	}

	public function removeFromRun() {
		return $this->delete();
	}

	protected function checkRelativeTo() {
		$this->wait_minutes_true = !($this->wait_minutes === null || trim($this->wait_minutes) == '');
		$this->relative_to_true = !($this->relative_to === null || trim($this->relative_to) == '');

		// disambiguate what user meant
		if ($this->wait_minutes_true AND ! $this->relative_to_true):  // user said wait minutes relative to, implying a relative to
			$this->relative_to = 'tail(survey_unit_sessions$created,1)'; // we take this as implied, this is the time someone arrived at this pause
			$this->relative_to_true = true;
		endif;
	}

	protected function checkWhetherPauseIsOver() {
		$conditions = array();

		// if a relative_to has been defined by user or automatically, we need to retrieve its value
		if ($this->relative_to_true) {
			$opencpu_vars = $this->getUserDataInRun($this->relative_to);
			$result = opencpu_evaluate($this->relative_to, $opencpu_vars, 'json');
			if ($result === null) {
				return false;
			}
			$this->relative_to_result = $relative_to = $result;
		}

		$bind_relative_to = false;

		if (!$this->wait_minutes_true AND $this->relative_to_true): // if no wait minutes but a relative to was defined, we just use this as the param (useful for complex R expressions)
			if ($relative_to === true):
				$conditions['relative_to'] = "1=1";
			elseif ($relative_to === false):
				$conditions['relative_to'] = "0=1";
			elseif (!is_array($relative_to) AND strtotime($relative_to)):
				$conditions['relative_to'] = ":relative_to <= NOW()";
				$bind_relative_to = true;
			else:
				alert("Pause {$this->position}: Relative to yields neither true nor false, nor a date, nor a time. " . print_r($relative_to, true), 'alert-warning');
				return false;
			endif;
		elseif ($this->wait_minutes_true):   // if a wait minutes was defined by user, we need to add its condition
			if (strtotime($relative_to)):
				$conditions['minute'] = "DATE_ADD(:relative_to, INTERVAL :wait_minutes MINUTE) <= NOW()";
				$bind_relative_to = true;
			else:
				alert("Pause {$this->position}: Relative to yields neither true nor false, nor a date, nor a time. " . print_r($relative_to, true), 'alert-warning');
				return false;
			endif;
		endif;

		if ($this->wait_until_date AND $this->wait_until_date != '0000-00-00'):
			$conditions['date'] = "CURDATE() >= :wait_date";
		endif;
		if ($this->wait_until_time AND $this->wait_until_time != '00:00:00'):
			$conditions['time'] = "CURTIME() >= :wait_time";
		endif;

		if (!empty($conditions)):
			$condition = implode($conditions, " AND ");

			$q = "SELECT ( {$condition} ) AS test LIMIT 1";

			$evaluate = $this->dbh->prepare($q); // should use readonly
			if (isset($conditions['minute'])):
				$evaluate->bindValue(':wait_minutes', $this->wait_minutes);
			endif;
			if ($bind_relative_to):
				$evaluate->bindValue(':relative_to', $relative_to);
			endif;

			if (isset($conditions['date'])):
				$evaluate->bindValue(':wait_date', $this->wait_until_date);
			endif;
			if (isset($conditions['time'])):
				$evaluate->bindValue(':wait_time', $this->wait_until_time);
			endif;

			$evaluate->execute();
			if ($evaluate->rowCount() === 1):
				$temp = $evaluate->fetch();
				$result = $temp['test'];
			endif;
		else:
			$result = true;
		endif;

		return $result;
	}

	public function test() {
		if (!$this->knittingNeeded($this->body)) {
			echo "<h3>Pause message</h3>";
			echo $this->getParsedBodyAdmin($this->body);
		}

		$results = $this->getSampleSessions();
		if (!$results) {
			return false;
		}

		if ($this->knittingNeeded($this->body)) {
			echo "<h3>Pause message</h3>";
			echo $this->getParsedBodyAdmin($this->body);
		}
		if ($this->checkRelativeTo()) {
			// take the first sample session
			$this->run_session_id = current($results)['id'];
			echo "<h3>Pause relative to</h3>";

			$opencpu_vars = $this->getUserDataInRun($this->relative_to);
			$session = opencpu_evaluate($this->relative_to, $opencpu_vars, 'json', null, true);

			echo opencpu_debug($session);
		}

		if (!empty($results)) {

			echo '<table class="table table-striped">
					<thead><tr>
						<th>Code</th>';
			if ($this->relative_to_true) {
				echo '<th>Relative to</th>';
			}
			echo '<th>Test</th>
					</tr></thead>
					<tbody>';

			foreach ($results AS $row):
				$this->run_session_id = $row['id'];

				$result = $this->checkWhetherPauseIsOver();
				echo "<tr>
						<td style='word-wrap:break-word;max-width:150px'><small>" . $row['session'] . " ({$row['position']})</small></td>";
				if ($this->relative_to_true) {
					echo "<td><small>" . stringBool($this->relative_to_result) . "</small></td>";
				}
				echo "<td>" . stringBool($result) . "</td>
					</tr>";

			endforeach;
			echo '</tbody></table>';
		}
	}

	public function exec() {
		$this->checkRelativeTo();
		if ($this->checkWhetherPauseIsOver()) {
			$this->end();
			return false;
		} else {
			$body = $this->getParsedBody($this->body);
			if ($body === false) {
				return true; // openCPU errors
			}
			return array(
				'body' => $body
			);
		}
	}

}
