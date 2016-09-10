<?php
namespace App\Workflow;

use App\Workflow\Eloquent\Definition;
use App\Workflow\Eloquent\Entry;
use App\Workflow\Eloquent\CurrentStep;
use App\Workflow\Eloquent\HistoryStep;

use App\Workflow\StateNotActivatedException;
use App\Workflow\StepNotFoundException;
use App\Workflow\CurrentStepNotFoundException;
use App\Workflow\ActionNotFoundException;
use App\Workflow\ActionNotAvailableException;
use App\Workflow\ResultNotAvailableException;
use App\Workflow\FunctionNotFoundException;
use App\Workflow\EntryNotFoundException;
use App\Workflow\ConfigNotFoundException;
use App\Workflow\SplitNotFoundException;
use App\Workflow\JoinNotFoundException;

class Workflow {

    /**
     * The workflow five states.
     *
     * @var int 
     */
    const OSWF_CREATED    = 1;
    const OSWF_ACTIVATED  = 2;
    const OSWF_SUSPENDED  = 3;
    const OSWF_COMPLETED  = 4;
    const OSWF_KILLED     = 5;

    /**
     * The workflow instance object.
     *
     * @var App\Workflow\Eloquent\Entry 
     */
    protected $entry;

    /**
     * The workflow config description.
     *
     * @var array
     */
    protected $wf_config;

    /**
     * user inputs 
     *
     * @var array
     */
    protected $inputs;

    /**
     * workflow constructor
     *
     * @param  string $entry_id
     * @return void
    */
    public function __construct($entry_id)
    {
        $entry = Entry::find($entry_id);
        if ($entry)
        {
            $this->entry = $entry;
            $this->wf_config = Definition::find($entry->definition_id)->contents;
            if (!$this->wf_config)
            {
                throw new ConfigNotFoundException();
            }
        }
        else
        {
            throw new EntryNotFoundException();
        }
    }

    /**
     * create workflow.
     *
     * @param string $definition_id
     * @return string
     */
    public static function createInstance($definition_id)
    {
        $entry = new Entry;
        $entry->definition_id = $definition_id;
        $entry->state = self::OSWF_CREATED;
        $entry->save();
        return new Workflow($entry->id);
    }

    /**
     * check action is available
     *
     * @param array $action_descriptor
     * @return boolean
     */
    private function isActionAvailable($action_descriptor)
    {
        if ($action_descriptor['restrict_to'] && $action_descriptor['restrict_to']['conditions'])
        {
            if (!$this->passesConditions($action_descriptor['restrict_to']['conditions']))
            {
                return false;
            }
        }
        return true;
    }

    /**
     * initialize workflow.
     *
     * @return void
     */
    public function initialize()
    {
        if (!$this->wf_config['initial_actions'])
        {
            throw new ActionNotFoundException();
        }

        $available_action_flg = false;
        foreach ($this->wf_config['initial_actions'] as $action_descriptor)
        {
            if ($this->isActionAvailable($action_descriptor))
            {
                $available_action_flg = true;
                break;
            }
        }
        if (!$available_action_flg)
        {
            throw new ActionNotAvailableException();
        }

        // confirm result whose condition is satified.
        $available_result_descriptor = $this->getAvailableResult($action_descriptor['results']);
        if (!$available_result_descriptor)
        {
            throw new ResultNotAvailableException();
        }
        // create new current step
        $this->createNewCurrentStep($available_result_descriptor, $action_descriptor['id'], '');
        // change workflow state to activited
        $this->changeEntryState(self::OSWF_ACTIVATED);
    }

    /**
     * get workflow state.
     *
     * @return string
     */
    public function getEntryState()
    {
        return $this->entry->state;
    }

    /**
     * change workflow state.
     *
     * @param string $new_state
     * @return void
     */
    public function changeEntryState($new_state)
    {
        $entry = Entry::find($this->entry->id);
        $entry->state = $new_state;
        $entry->save();
    }

    /**
     * complete workflow.
     *
     * @param string $entry_id
     * @return void
     */
    protected function completeEntry($entry_id)
    {
        return $this->changeEntryState($entry_id, self::OSWF_COMPLETED);
    }

    /**
     * get current steps for workflow.
     *
     * @return array
     */
    public function getCurrentSteps()
    {
        return Entry::find($this->entry->id)->currentSteps;
    }

    /**
     *  move workflow step to history
     *
     * @param App\Workflow\Eloquent\CurrentStep $current_step
     * @param string $old_status
     * @return string previous_id 
     */
    private function moveToHistory($current_step, $old_status)
    {
        // add to history records
        $history_step = new HistoryStep;
        $history_step->fill($current_step->toArray());
        $history_step->status = $old_status ?: '';
        $history_step->caller = 'liuxu'; // fix me
        $history_step->finish_time = new \MongoDate(time());
        $history_step->save();
        // delete from current step
        $current_step->delete();

        return $history_step->id;
    }

    /**
     *  create new workflow step.
     *
     * @param array $result_descriptor
     * @param string $action_id
     * @param string $previous_id
     * @return void
     */
    private function createNewCurrentStep($result_descriptor, $action_id, $previous_id)
    {
        $new_current_step = new CurrentStep;
        $new_current_step->entry_id = $this->entry->id;
        $new_current_step->action_id = intval($action_id);
        $new_current_step->step_id = intval($result_descriptor['step']);
        $new_current_step->status = $result_descriptor['status'];
        $new_current_step->start_time = new \MongoDate(time());
        $new_current_step->previous_id = $previous_id ?: '';
        $new_current_step->owners =  $result_descriptor['owners'] ?: '';
        $new_current_step->save();

        // trigger before step
        $step_descriptor = $this->getStepDescriptor($result_descriptor['step']);
        $this->executeFunctions($step_descriptor['pre_functions']);
    }

    /**
     * transfer workflow step.
     *
     * @param array $current_steps
     * @param string $action;
     * @return void
     */
    private function transitionWorkflow($current_steps, $action_id)
    {
        foreach ($current_steps as $current_step)
        {
            $step_descriptor = $this->getStepDescriptor($current_step->step_id);
            $action_descriptor = $this->getActionDescriptor($step_descriptor['actions'], $action_id);
            if ($action_descriptor)
            {
                break;
            }
        }
        if (!$action_descriptor)
        {
            throw new ActionNotFoundException(); 
        }
        if (!$this->isActionAvailable($action_descriptor))
        {
            throw new ActionNotAvailableException();
        }

        // triggers before action
        $this->executeFunctions($action_descriptor['pre_functions']);

        // confirm result whose condition is satified.
        $available_result_descriptor = $this->getAvailableResult($action_descriptor['results'] ?: array());
        // triggers before result
        $this->executeFunctions($available_result_descriptor['pre_functions']);
        // triggers after step
        $this->executeFunctions($step_descriptor['post_functions']);
        // split workflow
        if ($available_result_descriptor['split'])
        {
            // get split result
            $split_descriptor = $this->getSplitDescriptor($available_result_descriptor['split']);
            if (!$split_descriptor)
            {
                throw new SplitNotFoundException();
            }

            // move current to history step
            $prevoius_id = $this->moveToHistory($current_step, $available_result_descriptor['old_status']);
            foreach ($split_descriptor['list'] as $result_descriptor)
            {
                $this->createNewCurrentStep($result_descriptor, $action_id, $prevoius_id);
            }
        }
        else if ($available_result_descriptor['join'])
        {
            // fix me. join logic will be realized, suggest using the propertyset
            // get join result
            $join_descriptor = $this->getJoinDescriptor($available_result_descriptor['join']);
            if (!$join_descriptor)
            {
                throw new JoinNotFoundException();
            }

            // move current to history step
            $prevoius_id = $this->moveToHistory($current_step, $available_result_descriptor['old_status']);
            if ($this->passesConditions($join_descriptor['conditions']))
            {
                // record other previous_ids by propertyset
                $this->createNewCurrentStep($join_descriptor, $action_id, $prevoius_id);
            }
        }
        else
        {
            // move current to history step
            $prevoius_id = $this->moveToHistory($current_step, $available_result_descriptor['old_status']);
            // create current step
            $this->createNewCurrentStep($available_result_descriptor, $action_id, $prevoius_id);
        }
        // triggers after result
        $this->executeFunctions($available_result_descriptor['post_functions']);
        // triggers after action
        $this->executeFunctions($action_descriptor['post_functions']);
    }

    /**
     * execute action 
     *
     * @param array $wf_config
     * @param string $action_id
     * @param array $inputs;
     * @return string
     */
    public function doAction($action_id, $inputs=array())
    {
        $state = $this->getEntryState($this->entry->id);
        if ($state != self::OSWF_CREATED && $state != self::OSWF_ACTIVATED)
        {
            throw new StateNotActivatedException();
        }

        $current_steps = $this->getCurrentSteps();
        if (!$current_steps)
        {
            throw new CurrentStepNotFoundException();
        }

        // set user inputs
        $this->inputs = $inputs;
        // complete workflow step transition
        $this->transitionWorkflow($current_steps, $action_id);
    }

    /**
     * get join descriptor from list.
     *
     * @param string $join_id
     * @return array 
     */
    private function getJoinDescriptor($join_id)
    {
        foreach ($this->wf_config['joins'] as $join)
        {
            if ($join['id'] == $join_id)
            {
                return $join;
            }
        }
        return array();
    }

    /**
     * get split descriptor from list.
     *
     * @param string $split_id
     * @return array 
     */
    private function getSplitDescriptor($split_id)
    {
        foreach ($this->wf_config['splits'] as $split)
        {
            if ($split['id'] == $split_id)
            {
                return $split;
            }
        }
        return array();
    }

    /**
     * get action descriptor from list.
     *
     * @param array $actions
     * @param string $action_id
     * @return array 
     */
    private function getActionDescriptor($actions, $action_id)
    {
        // get global config
        $actions = $actions ?: array();
        foreach ($actions as $action)
        {
            if ($action['id'] == $action_id)
            {
                return $action;
            }
        }
        return array();
    }

    /**
     *  get step configuration.
     *
     * @param array $steps
     * @param string $step_id
     * @return array
     */
    private function getStepDescriptor($step_id)
    {
        foreach ($this->wf_config['steps'] as $step)
        {
            if ($step['id'] == $step_id)
            {
                return $step;
            }
        }
        return array();
    }

    /**
     * save workflow configuration info.
     *
     * @param array $info
     * @return void
     */
    public static function saveWorkflowDefinition($info)
    {
        $definition = $info['_id'] ? Definition::find($info['_id']) : new Definition;
        $definition->fill($info);
        $definition->save();
    }

    /**
     * remove configuration info.
     *
     * @param string $definition_id
     * @return void
     */
    public static function removeWorkflowDefinition($definition_id)
    {
        Definition::find($definition_id)->delete();
    }

    /**
     * get all available actions
     *
     * @return array
     */
    public function getAvailableActions()
    {
        $available_actions = array();
        // get current steps
        $current_steps = $this->getCurrentSteps();
        foreach ($current_steps as $current_step)
        {
            $actions = $this->getAvailableActionsFromStep($current_step->step_id);
            $actions && $available_actions += $actions;
        }
        return $available_actions;
    }

    /**
     * get available actions for step
     *
     * @param string $step_id
     * @return array
     */
    private function getAvailableActionsFromStep($step_id)
    {
        $step_descriptor = $this->getStepDescriptor($step_id);
        if (!$step_descriptor)
        {
            throw new StepNotFoundException();
        }
        if (!$step_descriptor['actions'])
        {
            return array();
        }
        // global conditions for step
        if (!$this->isActionAvailable($step_descriptor))
        {
            return array();
        }

        $available_actions = array();
        foreach ($step_descriptor['actions'] as $action)
        {
            if ($this->isActionAvailable($action))
            {
                $available_actions[] = array('id' => $action['id'], 'name' => $action['name'], 'screen' => $action['screen'] ?: '');
            }
        }

        return $available_actions;
    }

    /**
     * get available result from result-list 
     *
     * @param array $results_descriptor
     * @return array
     */
    public function getAvailableResult($results_descriptor)
    {
        $available_result_descriptor = array();

        // confirm result whose condition is satified.
        foreach ($results_descriptor as $result_descriptor)
        {
            if ($result_descriptor['conditions'])
            {
                if ($this->passesConditions($result_descriptor['conditions']))
                {
                    $available_result_descriptor = $result_descriptor;
                    break;
                }
            }
            else
            {
                $available_result_descriptor = $result_descriptor;
            }
        }
        return $available_result_descriptor;
    }

    /**
     * check conditions is passed
     *
     * @param array $conditions
     * @return boolean
     */
    private function passesConditions($conditions)
    {
        $type = $conditions['type'] ?: 'and';
        $result = $type == 'and' ? true : false;

        foreach ($conditions['list'] as $condition)
        {
            $tmp = $this->passesCondition($condition);
            if ($type == 'and' && !$tmp)
            {
                return false;
            }
            if ($type == 'or' && $tmp)
            {
                return true;
            }
        }
        return $result;
    }

    /**
     * check condition is passed
     *
     * @param array $condition
     * @return boolean
     */
    private function passesCondition($condition)
    {
        return $this->executeFunction($condition);
    }

    /**
     * execute functions
     *
     * @param array function
     * @return void
     */
    private function executeFunctions($functions)
    {
        if (!$functions || !is_array($functions))
        {
            return;
        }

        foreach ($functions as $function) {
            $this->executeFunction($function);
        }
    }

    /**
     * execute function
     *
     * @param array $function
     * @return mixed
     */
    private function executeFunction($function)
    {
        $method = explode('@', $function['name']);
        $class = new $method[0];
        $action = $method[1] ?: 'handle';

        // check handle function exists
        if (!method_exists($class, $action))
        {
            throw new FunctionNotFoundException();
        }
        $args = $function['args'] ?: array();
        // generate temporary vars
        $tmp_vars = $this->genTmpVars($args);
        // call handle function
        return $class->$action($tmp_vars);
    }

    /**
     * get all workflows' name.
     *
     * @return array
     */
    public static function getWorkflowNames()
    {
        return Definition::all(['name']);
    }

    /**
     * generate temporary variable.
     *
     * @return array
     */
    private function genTmpVars($args=array())
    {
        $tmp_vars = array();
        foreach ($this->entry as $key => $val)
        {
            $tmp_vars[$key] = $val;
        }
        $tmp_vars['inputs'] = $this->inputs;

        return array_merge($tmp_vars, $args);
    }

    /**
     * get property set
     *
     * @return mixed 
     */
    public function getPropertySet($key)
    {
        return $key ? $this->entry->propertysets[$key] : $this->entry->propertysets;
    }

    /**
     * add property set 
     *
     * @return void 
     */
    public function setPropertySet($key, $val)
    {
        $this->entry->propertysets = array_merge($this->entry->propertysets ?: [], array($key => $val));
        $this->entry->save();
    }

    /**
     * remove property set
     *
     * @return void 
     */
    public function removePropertySet($key)
    {
        $this->entry->unset($key ? ('propertysets.' . $key) : 'propertysets');
    }

    /**
     * get used screens in the workflow 
     *
     * @return array 
     */
    public static function getScreens($contents)
    {
        $screen_ids = [];
        $steps = isset($contents['steps']) && $contents['steps'] ? $contents['steps'] : [];
        foreach ($steps as $step)
        {
            if (!isset($step['actions']) || !$step['actions'])
            {
                continue;
            }
            foreach ($step['actions'] as $action)
            {
                if (!isset($action['screen']) || !$action['screen'])
                {
                    continue;
                }

                $action['screen'] !=  '-1' && !in_array($action['screen'], $screen_ids) && $screen_ids[] = $action['screen'];
            }
        }

        return $screen_ids;
    }

    /**
     * get step num 
     *
     * @return int 
     */
    public static function getStepNum($contents)
    {
        $screen_ids = [];
        $steps = isset($contents['steps']) && $contents['steps'] ? $contents['steps'] : [];
        return count($steps);
    }
}
