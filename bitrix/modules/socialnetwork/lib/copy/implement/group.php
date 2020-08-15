<?php
namespace Bitrix\Socialnetwork\Copy\Implement;

use Bitrix\Main\Copy\Container;
use Bitrix\Main\Copy\CopyImplementer;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Copy\Integration\Feature;
use Bitrix\Socialnetwork\WorkgroupSiteTable;

class Group extends CopyImplementer
{
	const GROUP_COPY_ERROR = "GROUP_COPY_ERROR";

	protected $executiveUserId;

	private $changedFields = [];

	/**
	 * @var Feature[]
	 */
	private $features = [];

	private $projectTerm = [];

	/**
	 * @var UserGroupHelper|null
	 */
	private $userGroupHelper = null;

	public function __construct($executiveUserId)
	{
		parent::__construct();

		$this->executiveUserId = $executiveUserId;
	}

	/**
	 * Writes feature implementer to the copy queue.
	 *
	 * @param Feature $feature Feature implementer.
	 */
	public function setFeature(Feature $feature)
	{
		$this->features[] = $feature;
	}

	/**
	 * Setting the start date of a project to update dates in entities.
	 *
	 * @param array $projectTerm ["start_point" => "", "end_point" => ""].
	 */
	public function setProjectTerm(array $projectTerm)
	{
		$this->projectTerm = $projectTerm;
	}

	public function setChangedFields($changedFields)
	{
		$this->changedFields = array_merge($this->changedFields, $changedFields);
	}

	/**
	 * Record helper object to update the list of moderators when copying.
	 *
	 * @param UserGroupHelper $userGroupHelper Helper object.
	 */
	public function setUserGroupHelper(UserGroupHelper $userGroupHelper)
	{
		$this->userGroupHelper = $userGroupHelper;
	}

	public function add(Container $container, array $fields)
	{
		$groupId = \CSocNetGroup::createGroup($this->executiveUserId, $fields, false);

		if (!$groupId)
		{
			//todo application exception
			$this->result->addError(new Error("System error", self::GROUP_COPY_ERROR));
		}
		else
		{
			\CSocNetFeatures::setFeature(SONET_ENTITY_GROUP, $groupId, "files", true, false);

			if ($fields["OWNER_ID"] != $fields["OLD_OWNER_ID"])
			{
				\CSocNetUserToGroup::setOwner($fields["OWNER_ID"], $groupId);
			}

			if ($this->userGroupHelper)
			{
				$this->userGroupHelper->changeModerators($groupId);
			}
		}

		return $groupId;
	}

	public function getFields(Container $container, $entityId)
	{
		$fields = [];

		$queryObject = \CSocNetGroup::getList(
			["ID" => "DESC"], ["ID" => (int) $entityId], false, false, ["*"]);
		while ($group = $queryObject->fetch())
		{
			if ($group["IMAGE_ID"] > 0)
			{
				$group["IMAGE_ID"] = \CFile::makeFileArray($group["IMAGE_ID"]);
			}

			$fields["SITE_ID"] = $this->getSiteIds($group["ID"]);

			$fields = $group;
		}

		return $fields;
	}

	public function prepareFieldsToCopy(Container $container, array $fields)
	{
		if (!empty($this->changedFields))
		{
			$fields = $this->changeFields($fields);
		}

		if ($fields["PROJECT"] == "Y" && $this->projectTerm)
		{
			if (!empty($this->projectTerm["start_point"]) && !empty($this->projectTerm["end_point"]))
			{
				$fields = $this->getFieldsProjectTerm($fields);
			}
			elseif (!empty($this->projectTerm["start_point"]))
			{
				$fields = $this->getRecountFieldsProjectTerm($fields, $this->projectTerm["start_point"]);
			}
		}

		$fields = $this->prepareExtranetFields($fields);

		unset($fields["ID"]);
		unset($fields["DATE_CREATE"]);
		unset($fields["DATE_UPDATE"]);
		unset($fields["DATE_ACTIVITY"]);

		return $fields;
	}

	/**
	 * Starts copying children entities.
	 *
	 * @param Container $container
	 * @param int $entityId Group id.
	 * @param int $copiedEntityId Copied group id.
	 * @return Result
	 */
	public function copyChildren(Container $container, $entityId, $copiedEntityId)
	{
		$copiedEntityId = (int) $copiedEntityId;
		if (!$copiedEntityId)
		{
			return new Result();
		}

		$this->copyUfFields($entityId, $copiedEntityId, "SONET_GROUP");

		foreach ($this->features as $feature)
		{
			//todo Perhaps it�s worth making the parameters not in the array, but in the object.
			// Until I made a decision, I do not want to write this to the interface.
			if (method_exists($feature, "setProjectTerm"))
			{
				$feature->setProjectTerm($this->projectTerm);
			}
			$feature->copy($entityId, $copiedEntityId);
		}

		return $this->getResult();
	}

	private function changeFields(array $fields)
	{
		if (!empty($fields["OWNER_ID"]))
		{
			$fields["OLD_OWNER_ID"] = $fields["OWNER_ID"];
		}

		foreach ($this->changedFields as $fieldId => $fieldValue)
		{
			if (array_key_exists($fieldId, $fields))
			{
				$fields[$fieldId] = $fieldValue;
			}
		}

		return $fields;
	}

	private function getFieldsProjectTerm($fields)
	{
		try
		{
			$projectTerm = [
				"project" => true
			];

			$startPoint = $this->projectTerm["start_point"];
			$endPoint = $this->projectTerm["end_point"];

			$phpDateFormat = \Bitrix\Main\Type\DateTime::convertFormatToPhp(FORMAT_DATE);

			$newDateStart = new \DateTime($startPoint);
			$fields["PROJECT_DATE_START"] = $newDateStart->format($phpDateFormat);

			$newDateEnd = new \DateTime($endPoint);
			$fields["PROJECT_DATE_FINISH"] = $newDateEnd->format($phpDateFormat);

			$projectTerm["start_point"] = $fields["PROJECT_DATE_START"];
			$projectTerm["end_point"] = $fields["PROJECT_DATE_FINISH"];

			$this->setProjectTerm($projectTerm);
		}
		catch (\Exception $exception)
		{
			$fields["PROJECT_DATE_FINISH"] = "";
			$this->result->addError(new Error($exception->getMessage()));
		}

		return $fields;
	}

	private function getRecountFieldsProjectTerm($fields, $startPoint)
	{
		try
		{
			$projectTerm = [
				"project" => true,
				"old_start_point" => $fields["PROJECT_DATE_START"]
			];

			$oldDateStart = new \DateTime($fields["PROJECT_DATE_START"]);

			$phpDateFormat = \Bitrix\Main\Type\DateTime::convertFormatToPhp(FORMAT_DATE);

			$newDateStart = new \DateTime($startPoint);
			$fields["PROJECT_DATE_START"] = $newDateStart->format($phpDateFormat);

			if (!empty($fields["PROJECT_DATE_FINISH"]))
			{
				$dateFinish = new \DateTime($fields["PROJECT_DATE_FINISH"]);
				$interval = new \DateInterval("PT".($dateFinish->getTimestamp()-$oldDateStart->getTimestamp())."S");
				$newDateStart->add($interval);
				$fields["PROJECT_DATE_FINISH"] = $newDateStart->format($phpDateFormat);
			}

			$projectTerm["start_point"] = $fields["PROJECT_DATE_START"];
			$projectTerm["end_point"] = $fields["PROJECT_DATE_FINISH"];

			$this->setProjectTerm($projectTerm);
		}
		catch (\Exception $exception)
		{
			$fields["PROJECT_DATE_FINISH"] = "";
			$this->result->addError(new Error($exception->getMessage()));
		}

		return $fields;
	}

	private function prepareExtranetFields(array $fields)
	{
		if (!Loader::includeModule("extranet") || !$this->isExtranetSite($fields["SITE_ID"]))
		{
			if (
				Loader::includeModule("extranet") &&
				!$this->isExtranetSite($fields["SITE_ID"]) &&
				$this->changedFields["IS_EXTRANET_GROUP"] == "Y"
			)
			{
				$fields["SITE_ID"][] = \CExtranet::getExtranetSiteID();
				$fields["VISIBLE"] = "N";
				$fields["OPENED"] = "N";
			}
		}
		elseif (Loader::includeModule("extranet") && $this->isExtranetSite($fields["SITE_ID"]))
		{
			$fields["SITE_ID"] = $this->getSiteIds($fields["ID"]);
		}

		return $fields;
	}

	private function getSiteIds(int $groupId): array
	{
		$siteIds = [];

		$queryObject = WorkgroupSiteTable::getList([
			"filter" => [
				"GROUP_ID" => $groupId
			],
			"select" => ["SITE_ID"]
		]);
		while ($workGroupSite = $queryObject->fetch())
		{
			$siteIds[] = $workGroupSite["SITE_ID"];
		}
		$siteIds = array_unique($siteIds);

		return $siteIds;
	}

	private function isExtranetSite(array $siteIds): bool
	{
		foreach ($siteIds as $siteId)
		{
			if (\CExtranet::isExtranetSite($siteId))
			{
				return true;
			}
		}

		return false;
	}
}