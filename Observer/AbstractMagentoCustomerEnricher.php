<?php
/**
 * Copyright © 2016 X2i.
 */

namespace Gigya\GigyaIM\Observer;

use Gigya\CmsStarterKit\user\GigyaUser;
use Gigya\GigyaIM\Api\GigyaAccountRepositoryInterface;
use Gigya\GigyaIM\Helper\GigyaSyncHelper;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Customer;
use Magento\Framework\Event\ManagerInterface;
use \Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * AbstractMagentoCustomerEnricher
 *
 * Will enrich a Magento customer entity's fields with the Gigya account data.
 *
 * @author      vlemaire <info@x2i.fr>
 *
 * When it's triggered it will :
 * . check that the Magento data have to be enriched
 * . enrich the Magento required fields with the Gigya attributes (first name, last name, email)
 * . trigger the event AbstractMagentoCustomerEnricher::EVENT_MAP_GIGYA_TO_MAGENTO so that the Gigya data could be enriched with third party code and with the extended fields mapping
 *
 */
abstract class AbstractMagentoCustomerEnricher extends AbstractEnricher implements ObserverInterface
{
    /**
     * This event is dispatched when the enrichment has been done
     */
    const EVENT_MAP_GIGYA_TO_MAGENTO = 'gigya_map_to_magento';

    /** @var  CustomerRepositoryInterface\ */
    protected $customerRepository;

    /** @var  GigyaAccountRepositoryInterface */
    protected $gigyaAccountRepository;

    /** @var  GigyaSyncHelper */
    protected $gigyaSyncHelper;

    /** @var ManagerInterface */
    protected $eventDispatcher;

    /** @var  LoggerInterface */
    protected $logger;

    /**
     * AbstractMagentoCustomerEnricher constructor.
     *
     * @param CustomerRepositoryInterface $customerRepository
     * @param GigyaAccountRepositoryInterface $gigyaAccountRepository
     * @param GigyaSyncHelper $gigyaSyncHelper
     * @param ManagerInterface $eventDispatcher
     * @param LoggerInterface $logger
     */
    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        GigyaAccountRepositoryInterface $gigyaAccountRepository,
        GigyaSyncHelper $gigyaSyncHelper,
        ManagerInterface $eventDispatcher,
        LoggerInterface $logger
    ) {
        $this->customerRepository = $customerRepository;
        $this->gigyaAccountRepository = $gigyaAccountRepository;
        $this->gigyaSyncHelper = $gigyaSyncHelper;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
    }

    /**
     * Check if Magento customer entity must be enriched with the Gigya's account data.
     *
     * @param Customer $magentoCustomer
     * @return bool True if the customer is not null, not flagged as deleted, not a new customer, not flagged has already synchronized, has a non empty gigya_uid value
     */
    protected function shallUpdateMagentoCustomerWithGigyaAccount($magentoCustomer)
    {
        $result =
            $magentoCustomer != null
            && !$magentoCustomer->isDeleted()
            && !$magentoCustomer->isObjectNew()
            && !$this->retrieveRegisteredCustomer($magentoCustomer)
            && !(empty($magentoCustomer->getGigyaUid()))
            && !$this->gigyaSyncHelper->isCustomerIdExcludedFromSync(
                $magentoCustomer->getId(), GigyaSyncHelper::DIR_G2CMS
            );

        return $result;
    }

    /**
     * Method called if an exception is caught when dispatching event AbstractMagentoCustomerEnricher::EVENT_MAP_GIGYA_TO_MAGENTO
     *
     * Default behavior is to log a warning (exception is muted)
     *
     * @param $e \Exception
     * @param $magentoCustomer Customer
     * @param $gigyaAccountData GigyaUser
     * @param $gigyaAccountLoggingEmail string
     * @return boolean Whether the enrichment can go on or not. Default is true.
     */
    protected function processEventMapGigyaToMagentoException($e, $magentoCustomer, $gigyaAccountData, $gigyaAccountLoggingEmail) {

        // Ignore : enrichment shall not fail on third party code exception
        $this->logger->warning(
            'Exception raised when enriching Magento customer with Gigya data.',
            [
                'exception' => $e,
                'customer_entity_id' => ($magentoCustomer != null) ? $magentoCustomer->getEntityId() : 'customer is null',
                'gigya_uid' => ($gigyaAccountData != null) ? $gigyaAccountData->getUID() : 'Gigya data are null',
                'gigya_logging_email' => $gigyaAccountLoggingEmail
            ]
        );

        return true;
    }

    /**
     * Given a Magento customer, retrieves the corresponding Gigya account data from the Gigya service.
     *
     * @param $magentoCustomer
     * @return array [
     *                  'gigya_user' => GigyaUser : the data from the Gigya service
     *                  'gigya_logging_email' => string : the email for logging as set on this Gigya account
     *               ]
     */
    protected function getGigyaDataForEnrichment($magentoCustomer)
    {
        $gigyaAccountData = $this->gigyaAccountRepository->get($magentoCustomer->getGigyaUid());
        $gigyaAccountLoggingEmail = $this->gigyaSyncHelper->getMagentoCustomerAndLoggingEmail($gigyaAccountData)['logging_email'];

        return [
            'gigya_user' => $gigyaAccountData,
            'gigya_logging_email' => $gigyaAccountLoggingEmail
        ];
    }

    /**
     * Performs the enrichment of the customer with the Gigya data.
     *
     * The event AbstractMagentoCustomerEnricher::EVENT_MAP_GIGYA_TO_MAGENTO is triggered here with parameters :
     * gigya_user => GigyaUser
     * customer => CustomerInterface
     *
     * @param $magentoCustomer Customer
     * @param $gigyaAccountData GigyaUser
     * @param $gigyaAccountLoggingEmail string
     * @return CustomerInterface The updated Magento customer entity.
     * @throws \Exception
     */
    protected function enrichMagentoCustomerWithGigyaData($magentoCustomer, $gigyaAccountData, $gigyaAccountLoggingEmail)
    {
        $this->pushRegisteredCustomer($magentoCustomer);

        $this->gigyaSyncHelper->updateMagentoCustomerRequiredFieldsWithGygiaData($magentoCustomer, $gigyaAccountData, $gigyaAccountLoggingEmail);

        $magentoCustomerDataModel = $magentoCustomer->getDataModel();
        try {
            $this->eventDispatcher->dispatch(self::EVENT_MAP_GIGYA_TO_MAGENTO, [
                "gigya_user" => $gigyaAccountData,
                "customer" => $magentoCustomerDataModel
            ]);
        } catch (\Exception $e) {
            if (!$this->processEventMapGigyaToMagentoException($e, $magentoCustomer, $gigyaAccountData,
                $gigyaAccountLoggingEmail)
            ) {
                throw $e;
            }
        }

        return $magentoCustomerDataModel;
    }

    /**
     * Will synchronize Magento account entity with Gigya account if needed.
     *
     * @param Observer $observer Must hang a data 'customer' of type Magento\Customer\Model\Customer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var Customer $customer */
        $magentoCustomer = $observer->getData('customer');

        if ($this->shallUpdateMagentoCustomerWithGigyaAccount($magentoCustomer)) {

            $gigyaData = $this->getGigyaDataForEnrichment($magentoCustomer);
            $magentoCustomerDataModel = $this->enrichMagentoCustomerWithGigyaData($magentoCustomer, $gigyaData['gigya_user'], $gigyaData['gigya_logging_email']);
            $this->customerRepository->save($magentoCustomerDataModel);
        }
    }
}