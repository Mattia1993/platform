# Oro\Bundle\EmailBundle\Entity\Email

## FIELDS

### activityTargets

A records to which the email record associated with.

## FILTERS

### exclude-current-user

Indicates whether the current user should be excluded from the result.

## SUBRESOURCES

### suggestions

#### get_subresource

Get entities that might be associated with the email.

### activityTargets

#### get_subresource

Get full information about records to which the email associated.

#### get_relationship

Get records to which the email associated.

#### add_relationship

Associate records with the email.

#### update_relationship

Completely replace association between records and the email.

#### delete_relationship

Delete association between records and the email.
