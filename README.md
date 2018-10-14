********************************************************************************
# Locking API

Luke Stevens, Murdoch Children's Research Institute https://www.mcri.edu.au

********************************************************************************
## Summary

Read lock status, lock and unlock data entry forms via API calls.

Post api token and `record[,event][,instrument][,instance]` to your regular system API endpoint, using the following query string: 

```http
?NOAUTH&type=module&prefix=locking_api&page=<action page>
```

`<action page>` must be one of:
* **status**: Obtain current lock state of the `record[,event][,instrument][,instance]`
* **lock**:   Lock the `record[,event][,instrument][,instance]`
* **unlock**: Unlock the `record[,event][,instrument][,instance]`

Note it is not possible to lock a form that has not yet had any data entry.

## Example 

```bash
curl -d "token=ABCDEF0123456789ABCDEF0123456789&returnFormat=json&record=1001&event=event_1_arm_1&instrument=medication&instance=4"
    "https://redcap.ourplace.edu/api/?NOAUTH&type=module&prefix=locking_api&page=status"
```

## Return Format (optional)
Return Format options are the usual csv, json or xml (default). 

## Record/Event/Instrument/Instance Specification

* **record**: Required. 
* **event**: A valid unique name or event id for the project (if longitudinal).
* **instrument**: A valid form name for the project, as per data dictionary.
* **instance**: Instance number for repeating event or instrument.

Event will be ignored if the project is not longitudinal.

If an instance value >=2 is submitted for a non-repeating event/instrument then an error will be returned.

### Examples
* Screening form in Event 1 for record 1001 (instance not required as not a repeating form):
    ```http
    record=1001&event=event_1_arm_1&instrument=screening
    ```

* First instance of repeating Concomitant Medication form in Event 1 for record 1001:
    ```http
    record=1001&event=event_1_arm_1&instrument=concomitant_medication&instance=1
    ```

* All instances (if any) of repeating Concomitant Medication form in Event 1 for record 1001: 
    ```http
    record=1001&event=event_1_arm_1&instrument=concomitant_medication
    ```

* All forms in Event 1 for record 1001 (note the instrument & instance parameters are empty): 
    ```http
    record=1001&event=event_1_arm_1 OR record=1001&event=event_1_arm_1&instrument=&instance=
    ```

* Visit Admin forms across all events for record 1001: 
    ```http
    record=1001&event=&instrument=visit_admin
    ```

* Error - record required: 
    ```http
    record=&event=&instrument=visit_admin
    ```

* Error - not a repeating event: 
    ```http
    record=1001&event=event_1_arm_1&instrument=&instance=2
    ```

## Returned Data

All API calls (*i.e.*, status, lock, unlock) return a set of the event/instrument/instance combinations for the record/event/instrument/instance requested. For each combination the lock status is returned as follows:

* **1**: Locked
* **0**: Not locked (but form data exists)
* **&lt;empty&gt;**: No data exists for form

Note that this enables you to determine whether data has ever been entered for an instrument, which is not possible using the regular 'Export Records' or 'Export Reports' API methods. ;-) (Also note it is not possible to lock forms that have not yet had any data entry.)

### CSV Example

input: `record=1001&instrument=visit_admin`

output:
```csv
record,redcap_event_name,instrument,instance,lock_status,username,timestamp
1001,visit_1_arm_1,visit_admin,1,1,luke.stevens,2018-12-31 23:59:59
1001,visit_2_arm_1,visit_admin,1,0,,
1001,visit_3_arm_1,visit_admin,1,,,
```

## Min REDCap Version
Min REDCap version is 8.2.3 due to use of array as argument to `REDCap::getData()` when checking existence of record.
********************************************************************************
