<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) CampaignChain, Inc. <info@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Report\Analytics\MetricsPerActivityBundle\Util;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;

class Data
{
    const ONE_SERIES_PER_DIMENSION = 'one_series_per_dimension';

    const ALL_DIMENSIONS_PER_ACTIVITY = 'all_dimensions_per_activity';

    protected $em;

    private $serializer;

    public $campaign;

    public $activities;

    public $milestones;

    public $dimensions;

    public $campaignDuration;

    public $campaignData;

    public $dimensionsData;

    public $milestonesMarkings = null;

    public $milestonesData = null;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;

        // We'll need the serializer later
        $encoders = array(new JsonEncoder());
        $normalizers = array(new GetSetMethodNormalizer());
        $this->serializer = new Serializer($normalizers, $encoders);
    }

    public function setCampaign($campaign){
        $this->campaign = $campaign;
    }

    public function getCampaignData($campaign){
        $this->campaignData['duration'] = $this->getCampaignDuration($campaign);
        // TODO: Formatting should be handled by Datetime service.
        $this->campaignData['startDate'] = $campaign->getStartDate()->format('F d, Y H:i:s');
        $this->campaignData['endDate'] = $campaign->getEndDate()->format('F d, Y H:i:s');

        return $this->campaignData;
    }

    public function getCampaignSeries($campaign, $structure = self::ONE_SERIES_PER_DIMENSION){
        $activities = $this->getActivities($campaign);
        foreach($activities as $activity){
            $dimensions = $this->getMetrics($campaign, $activity->getActivity());
            foreach($dimensions as $dimension){
                $this->getFacts($campaign, $activity->getActivity(), $dimension->getMetric());
            }

            $seriesData[] = array(
                'activity' => $activity->getActivity(),
                'dimensions' => $this->dimensionsData,
            );
        }

        return $seriesData;
    }

    public function getActivitySeries($activity, $structure = self::ONE_SERIES_PER_DIMENSION){
        $dimensions = $this->getMetrics($activity->getCampaign(), $activity);
        foreach($dimensions as $dimension){
            $this->getFacts($activity->getCampaign(), $activity, $dimension->getMetric());
        }

        $seriesData[] = array(
            'activity' => $activity,
            'dimensions' => $this->dimensionsData,
        );

        return $seriesData;
    }

    public function getMilestonesData($campaign){
        $milestones = $this->getMilestones($campaign);

        foreach($milestones as $milestone){
            $this->milestonesData .= '{';
            $this->milestonesData .= '    x:'.$milestone->getJavascriptTimestamp().',';
            $this->milestonesData .= '    y: 0,';
            $this->milestonesData .= '    contents: "'.$milestone->getName()/*.'<br/>'.$milestone->getDue()->format('Y-m-d H:i')*/.'"';
            $this->milestonesData .= '},';
        }

        return rtrim($this->milestonesData, ',');
    }

    public function getMilestonesMarkings($campaign){
        $milestones = $this->getMilestones($campaign);

        foreach($milestones as $milestone){
            $this->milestonesMarkings .= '{';
            $this->milestonesMarkings .= 'xaxis: { from: '.$milestone->getJavascriptTimestamp().', to: '.$milestone->getJavascriptTimestamp().' }, color: "#EBCCD1"';
            $this->milestonesMarkings .= '},';
        }

        return $this->milestonesMarkings;
    }

    public function getActivities($campaign){
        // Find all activities of this campaign that do have report data
        $qb = $this->em->createQueryBuilder();
        $qb->select('r')
            ->from('CampaignChain\CoreBundle\Entity\ReportAnalyticsActivityFact', 'r')
            ->from('CampaignChain\CoreBundle\Entity\Activity', 'a')
            ->where('r.campaign = :campaignId')
            ->groupBy('r.activity')
            ->orderBy('a.startDate', 'ASC')
            ->setParameter('campaignId', $campaign->getId());
        $query = $qb->getQuery();
        return $this->activities = $query->getResult();
    }

    public function getCampaignDuration($campaign){
        // Get campaign duration in days
        $campaignStartDate = $campaign->getStartDate();
        $campaignEndDate = $campaign->getEndDate();
        return $this->campaignDuration = $campaignStartDate->diff($campaignEndDate)->format('%a');
    }

    public function getMilestones($campaign){
        $qb = $this->em->createQueryBuilder();
        $qb->select('m')
            ->from('CampaignChain\CoreBundle\Entity\Milestone', 'm')
            ->where('m.campaign = :campaignId')
            ->orderBy('m.startDate', 'ASC')
            ->setParameter('campaignId', $campaign->getId());
        $query = $qb->getQuery();
        return $this->milestones = $query->getResult();
    }

    /*
     * Get the report data per activity.
     */
    public function getMetrics($campaign, $activity){
        $qb = $this->em->createQueryBuilder();
        $qb->select('r')
            ->from('CampaignChain\CoreBundle\Entity\ReportAnalyticsActivityFact', 'r')
            ->where('r.activity = :activityId')
            ->andWhere('r.campaign = :campaignId')
            ->groupBy('r.metric')
            ->setParameter('activityId', $activity->getId())
            ->setParameter('campaignId', $campaign->getId());
        $query = $qb->getQuery();
        $this->dimensions = $query->getResult();
        return $this->dimensions;
    }

    /*
     * Get facts data per dimension.
     */
    public function getFacts($campaign, $activity, $metric){
        $qb = $this->em->createQueryBuilder();
        $qb->select('r')
            ->from('CampaignChain\CoreBundle\Entity\ReportAnalyticsActivityFact', 'r')
            ->where('r.activity = :activityId')
            ->andWhere('r.campaign = :campaignId')
            ->andWhere('r.metric = :metricId')
            ->orderBy('r.time', 'ASC')
            ->setParameter('activityId', $activity->getId())
            ->setParameter('campaignId', $campaign->getId())
            ->setParameter('metricId', $metric->getId());
        $query = $qb->getQuery();
        $facts = $query->getResult();

        $factsData = array();

        foreach($facts as $fact){
            // Collecting the data series
            $factsData[] = array($fact->getJavascriptTimestamp(), $fact->getValue());
        }

        $dimensionName = $metric->getName();
        $this->dimensionsData[$dimensionName]['data'] = $this->serializer->serialize($factsData, 'json');
        $this->dimensionsData[$dimensionName]['id'] = $metric->getId();
        $this->dimensionsData[$dimensionName]['percent'] = $this->getDimensionPercent($campaign, $activity, $metric);
    }

    public function getDimensionPercent($campaign, $activity, $metric){
        // Get value of earliest and latest entry to calculate percentage
        $qb = $this->em->createQueryBuilder();
        $qb->select('r.value')
            ->from('CampaignChain\CoreBundle\Entity\ReportAnalyticsActivityFact', 'r')
            ->where('r.activity = :activityId')
            ->andWhere('r.campaign = :campaignId')
            ->andWhere('r.metric = :metricId')
            ->orderBy('r.time', 'ASC')
            ->setMaxResults(1)
            ->setParameter('activityId', $activity->getId())
            ->setParameter('campaignId', $campaign->getId())
            ->setParameter('metricId', $metric->getId());
        $query = $qb->getQuery();
        $startValue = $query->getResult();

        $qb = $this->em->createQueryBuilder();
        $qb->select('r.value')
            ->from('CampaignChain\CoreBundle\Entity\ReportAnalyticsActivityFact', 'r')
            ->where('r.activity = :activityId')
            ->andWhere('r.campaign = :campaignId')
            ->andWhere('r.metric = :metricId')
            ->orderBy('r.time', 'DESC')
            ->setMaxResults(1)
            ->setParameter('activityId', $activity->getId())
            ->setParameter('campaignId', $campaign->getId())
            ->setParameter('metricId', $metric->getId());
        $query = $qb->getQuery();
        $endValue = $query->getResult();

        // calculate percentage:
        if($startValue[0]['value'] != 0){
            $startValue = $startValue[0]['value'];
            $endValue = $endValue[0]['value'];
            $percent = (($endValue - $startValue) / $startValue)*100;
        } else {
            $percent = 0;
        }

        //$data_percent = number_format( $percent * 100, 2 ) . '%';

        return $percent;
    }
}