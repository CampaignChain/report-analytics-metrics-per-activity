<?php
/*
 * Copyright 2016 CampaignChain, Inc. <info@campaignchain.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace CampaignChain\Report\Analytics\MetricsPerActivityBundle\Controller;

use CampaignChain\CoreBundle\Entity\Campaign;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

class PageController extends Controller
{
    public function indexAction(Request $request)
    {
        $campaign = [];
        $form = $this->createFormBuilder($campaign)
            ->setMethod('GET')
            ->add(
                'campaign',
                EntityType::class,
                [
                    'label' => 'Campaign',
                    'class' => 'CampaignChainCoreBundle:Campaign',
                    // Only display campaigns for selection that actually have report data
                    'query_builder' => function (EntityRepository $er) {
                        return $er->createQueryBuilder('campaign')
                            ->join('campaign.activityFacts', 'r')
                            ->groupBy('campaign.id')
                            ->orderBy('campaign.startDate', 'ASC');
                    },
                    'property' => 'name',
                    'empty_value' => 'Select a Campaign',
                    'empty_data' => null,
                ]
            )
            ->getForm();

        $form->handleRequest($request);

        $tplVars = [
            'page_title' => 'Metrics Per Activity',
            'form' => $form->createView(),
        ];

        if ($form->isValid()) {
            $tplVars['campaign'] = $campaign = $form->getData()['campaign'];
            $dataService = $this->get('campaignchain.report.analytics.metrics_per_activity.data');
            $tplVars['report_data'] = $dataService->getCampaignSeries($campaign);
            $tplVars['campaign_data'] = $dataService->getCampaignData($campaign);
            $tplVars['milestone_data'] = $dataService->getMilestonesData($campaign);
            $tplVars['markings_data'] = $dataService->getMilestonesMarkings($campaign);
        }

        return $this->render(
            'CampaignChainReportAnalyticsMetricsPerActivityBundle:Page:index.html.twig',
            $tplVars
        );
    }

    public function activityAction($id)
    {
        // TODO: If an activity is done, it cannot be edited.
        $activity = $this->getDoctrine()
            ->getRepository('CampaignChainCoreBundle:Activity')
            ->find($id);

        if (!$activity) {
            throw new \Exception(
                'No activity found for id '.$id
            );
        }

        $campaign = $activity->getCampaign();

        $dataService = $this->get('campaignchain.report.analytics.metrics_per_activity.data');

        return $this->render(
            'CampaignChainReportAnalyticsMetricsPerActivityBundle:Page:activity.html.twig',
            [
                'page_title' => 'Metrics Per Activity',
                'report_data' => $dataService->getActivitySeries($activity),
                'campaign_data' => $dataService->getCampaignData($campaign),
                'milestone_data' => $dataService->getMilestonesData($campaign),
                'markings_data' => $dataService->getMilestonesMarkings($campaign),
            ]
        );
    }
}
